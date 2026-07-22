<?php

namespace Parser;

use Lexer\Lexer;
use Lexer\Token;
use Lexer\TokenKind;
use Parser\Ast\AttributeNode;
use Parser\Ast\Block;
use Parser\Ast\ClassDecl;
use Parser\Ast\ConstDecl;
use Parser\Ast\Expr;
use Parser\Ast\FunctionDecl;
use Parser\Ast\MethodDecl;
use Parser\Ast\Param;
use Parser\Ast\Program;
use Parser\Ast\PropertyDecl;
use Parser\Ast\Span;
use Parser\Ast\ArrayElement;
use Parser\Ast\CatchClause;
use Parser\Ast\ClosureUse;
use Parser\Ast\ElseIfArm;
use Parser\Ast\MatchArm;
use Parser\Ast\StaticLocalDecl;
use Parser\Ast\Stmt;
use Parser\Ast\SwitchArm;
use Parser\Ast\UseItem;

/**
 * Recursive-descent + Pratt parser for the bootstrap PHP subset.
 *
 * Phase 1 supports:
 *   - top-level statements: expression-statement, echo, return, if/elseif/else,
 *     while, function declaration
 *   - expressions: integer / float / string / bool / null literals, variables,
 *     identifiers, function calls, binary arithmetic and comparison ops,
 *     assignment (=), parenthesised expressions, unary `-` and `!`
 *
 * Out of scope for phase 1 (added incrementally): classes, interfaces, traits,
 * enums, namespaces, use declarations, attributes, references, generators,
 * try/catch, match, switch, foreach, for, do-while, throw, include/require,
 * heredoc, complex string interpolation, and the rich expression surface
 * (member access, array literals, lambdas, etc.).
 *
 * Mirrors `crates/manticore-parser/src/parser.rs` AST shape so the JSON dump
 * of the two implementations can be diffed for parity.
 */
final class Parser
{
    /** @var Token[] */
    private array $tokens = [];
    private int $pos = 0;

    /**
     * Active PHP namespace; '' means the global namespace. Updated as
     * we see `namespace Foo\Bar;` statements during parsing.
     */
    private string $currentNamespace = '';

    /**
     * Active short-name → fully-qualified class alias map, populated
     * from `use Foo\Bar;` and `use Foo\Bar as Baz;` declarations.
     * Resets when we cross into a new namespace.
     *
     * @var array<string, string>
     */
    private array $useAliases = [];

    /**
     * Map: index of the token at which a doc-comment is "attached"
     * (i.e. the next non-DocComment token) → the doc-comment lexeme.
     * Built once at construction so parseFunctionDecl / parseMethod
     * can read the attached docblock without a separate walk.
     *
     * @var array<int, string>
     */
    private array $docCommentByPos = [];

    /**
     * Every doc comment, in source order — the flat list {@see Program::$docComments}
     * carries. Generic reification pre-scans it for bindings (`Box<float>`), which
     * are written ONLY in docblocks, including on statements deep inside a body.
     *
     * @var string[]
     */
    private array $allDocComments = [];

    /**
     * Anonymous-class declarations (`new class { … }`) parsed inline, hoisted
     * to the program's top-level statement list so they register + lower like
     * any class. `$anonClassCounter` makes each synthetic name unique.
     * @var ClassDecl[]
     */
    private array $hoistedClasses = [];
    private int $anonClassCounter = 0;

    /**
     * Convenience entry point. Lexes the source and parses it in one call.
     */
    public static function parseSource(string $source): Program
    {
        $tokens = (new Lexer())->scan($source);
        return (new self($tokens))->parseProgram();
    }

    /**
     * @param Token[] $tokens
     */
    public function __construct(array $tokens)
    {
        // Filter out DocComment tokens but remember each one's
        // attachment point — the index of the next non-DocComment
        // token. Subsequent parsing operates on a clean token stream.
        // Seed the int-keyed map with a sentinel string slot so
        // subsequent sparse writes stick (self-host's `[$intPos] = $str`
        // on an empty `[]` silently drops the entry).
        $this->docCommentByPos = ['__seed__' => ''];
        $filtered = [];
        $pendingDoc = null;
        foreach ($tokens as $tok) {
            if ($tok->kind === TokenKind::DocComment) {
                $pendingDoc = $tok->lexeme;
                continue;
            }
            if ($pendingDoc !== null) {
                $this->docCommentByPos[(string)count($filtered)] = $pendingDoc;
                $this->allDocComments[] = $pendingDoc;
                $pendingDoc = null;
            }
            $filtered[] = $tok;
        }
        $this->tokens = $filtered;
        $this->pos = 0;
    }

    public function parseProgram(): Program
    {
        if ($this->check(TokenKind::OpenTag)) {
            $this->advance();
        }

        $stmts = [];
        while (!$this->isAtEnd()) {
            if ($this->check(TokenKind::CloseTag)) {
                $this->advance();
                continue;
            }
            $stmts[] = $this->parseStatement();
        }
        // Append hoisted anonymous classes as top-level declarations (order is
        // irrelevant — the class pre-pass scans every class statement).
        foreach ($this->hoistedClasses as $decl) {
            $stmts[] = Stmt::class_($decl, $decl->span);
        }
        // Capture the file's final namespace + use-aliases context so
        // compile-time short-name resolution can map `Foo` → `Use\Foo`.
        return new Program($stmts, $this->currentNamespace, $this->useAliases, $this->allDocComments);
    }

    // ── Statements ───────────────────────────────────────────────────────────

    private function parseStatement(): Stmt
    {
        // A doc comment at the head of a statement (inline `/** @var T $x */`
        // before an assignment) — captured before anything advances so an
        // expression statement can carry it for local-type seeding.
        $stmtDoc = isset($this->docCommentByPos[(string)$this->pos])
            ? $this->docCommentByPos[(string)$this->pos]
            : null;
        // Collect leading attributes; they apply to whatever declaration
        // follows (function, class, or property at the head of a class
        // body — but here only function and class apply).
        $attrs = $this->collectAttributes();

        $tok = $this->peek();

        if ($tok->kind === TokenKind::Keyword) {
            $kw = strtolower($tok->lexeme);
            if ($kw === 'namespace') return $this->parseNamespace();
            if ($kw === 'use')       return $this->parseUseDecl();
            if ($kw === 'class' || $kw === 'final' || $kw === 'abstract' || $kw === 'readonly') {
                if ($kw === 'class' || $this->isClassModifierFollowedByClass()) {
                    return $this->parseClass($attrs, $stmtDoc);
                }
            }
            if ($kw === 'interface') return $this->parseClass($attrs, $stmtDoc);
            if ($kw === 'trait')     return $this->parseClass($attrs, $stmtDoc);
            if ($kw === 'enum')      return $this->parseClass($attrs, $stmtDoc);
            if ($kw === 'function')  return $this->parseFunctionDecl($attrs);
            if ($kw === 'return')    return $this->parseReturn();
            if ($kw === 'echo')      return $this->parseEcho();
            if ($kw === 'if')        return $this->parseIf();
            if ($kw === 'while')     return $this->parseWhile();
            if ($kw === 'do')        return $this->parseDoWhile();
            if ($kw === 'for')       return $this->parseFor();
            if ($kw === 'foreach')   return $this->parseForeach();
            if ($kw === 'break')     return $this->parseBreakContinue('Break');
            if ($kw === 'continue')  return $this->parseBreakContinue('Continue');
            if ($kw === 'throw')     return $this->parseThrow();
            if ($kw === 'try')       return $this->parseTry();
            if ($kw === 'switch')    return $this->parseSwitch();
            if ($kw === 'static' && $this->isStaticLocalDecl()) {
                return $this->parseStaticLocal();
            }
            if ($kw === 'global')    return $this->parseGlobal();
            if ($kw === 'goto')      return $this->parseGoto();
            if ($kw === 'declare')   return $this->parseDeclare();
        }

        // Statement label `name:` — an identifier immediately followed by a
        // single colon at statement position (a `goto` target). Distinct from
        // `::`, ternary `:`, and switch case labels (those never start a bare
        // statement with `IDENT :`).
        if ($tok->kind === TokenKind::Identifier) {
            $next = $this->tokens[$this->pos + 1] ?? null;
            if ($next !== null && $next->kind === TokenKind::Colon) {
                $span = $this->span();
                $name = $this->advance()->lexeme;   // the label name
                $this->advance();                    // ':'
                return new \Parser\Ast\LabelStmt($name, $span);
            }
        }

        // Bare block.
        if ($this->check(TokenKind::OpenBrace)) {
            $span = $this->span();
            $block = $this->parseBlock();
            return Stmt::if_(Expr::bool(true, $span), $block, [], null, $span);
        }

        if ($attrs !== []) {
            throw $this->error('attributes must precede a declaration');
        }

        return $this->parseExpressionStatement($stmtDoc);
    }

    /** True iff the head token is a class-modifier keyword whose next
     *  non-modifier token starts a class declaration. */
    private function isClassModifierFollowedByClass(): bool
    {
        $i = $this->pos;
        while ($i < count($this->tokens)) {
            $t = $this->tokens[$i];
            if ($t->kind !== TokenKind::Keyword) {
                return false;
            }
            $lex = strtolower($t->lexeme);
            if ($lex === 'class') {
                return true;
            }
            if ($lex === 'final' || $lex === 'abstract' || $lex === 'readonly') {
                $i = $i + 1;
                continue;
            }
            return false;
        }
        return false;
    }

    // ── Attributes ───────────────────────────────────────────────────────────

    /**
     * Collect a (possibly empty) leading run of `#[...]` attribute groups.
     *
     * @return AttributeNode[]
     */
    private function collectAttributes(): array
    {
        $out = [];
        while ($this->check(TokenKind::AttributeStart)) {
            $span = $this->span();
            $this->advance();
            while (true) {
                $name = $this->parseClassName();
                $args = [];
                if ($this->check(TokenKind::OpenParen)) {
                    $args = $this->parseArgList();
                }
                $out[] = new AttributeNode($name, $args, $span);
                if (!$this->match(TokenKind::Comma)) {
                    break;
                }
            }
            $this->expect(TokenKind::CloseBracket, "expected ']' to close attribute group");
        }
        return $out;
    }

    // ── Namespace and use ────────────────────────────────────────────────────

    private function parseNamespace(): Stmt
    {
        $span = $this->span();
        $this->advance(); // 'namespace'
        $name = '';
        if (!$this->check(TokenKind::OpenBrace) && !$this->check(TokenKind::Semicolon)) {
            $name = $this->parseClassNameLiteral();
        }
        $name = ltrim($name, '\\');
        if ($this->check(TokenKind::OpenBrace)) {
            $prevNs = $this->currentNamespace;
            $prevUses = $this->useAliases;
            $this->currentNamespace = $name;
            $this->useAliases = [];
            try {
                $body = $this->parseBlock();
            } finally {
                $this->currentNamespace = $prevNs;
                $this->useAliases = $prevUses;
            }
            return Stmt::namespace_($name, $body, $span);
        }
        $this->expect(TokenKind::Semicolon, "expected ';' after namespace name");
        // Non-braced namespace declaration owns everything until the
        // next `namespace`/EOF, so we just install the context here
        // and let later `parseUseDecl` calls layer aliases onto it.
        $this->currentNamespace = $name;
        $this->useAliases = [];
        return Stmt::namespace_($name, null, $span);
    }

    private function parseUseDecl(): Stmt
    {
        $span = $this->span();
        $this->advance(); // 'use'

        $kind = 'class';
        if ($this->checkKeyword('function')) { $this->advance(); $kind = 'function'; }
        elseif ($this->checkKeyword('const')) { $this->advance(); $kind = 'const'; }

        $items = [];
        // Look ahead for the group form `use Foo\Bar\{A, B as C};` by
        // grabbing the prefix path piece-by-piece and watching for a
        // trailing `\{`. Falls back to the regular single-item path
        // when no brace follows.
        $prefix = $this->parseUseGroupPrefix();
        if ($prefix !== null && $this->match(TokenKind::OpenBrace)) {
            $items[] = $this->parseUseGroupItem($prefix, $kind);
            while ($this->match(TokenKind::Comma)) {
                if ($this->check(TokenKind::CloseBrace)) { break; } // trailing comma
                $items[] = $this->parseUseGroupItem($prefix, $kind);
            }
            $this->expect(TokenKind::CloseBrace, "expected '}' to close use group");
        } else {
            // No group → first FQN already consumed becomes a regular item.
            $items[] = $this->finishUseItem($prefix ?? '', $kind);
            while ($this->match(TokenKind::Comma)) {
                $items[] = $this->parseUseItem($kind);
            }
        }
        $this->expect(TokenKind::Semicolon, "expected ';' after use");
        return Stmt::useDecl($items, $span);
    }

    /**
     * Read a backslash-separated FQN, stopping ONE step before the
     * final `\` if it's followed by `{` (group form). Returns the
     * accumulated prefix string (with no trailing `\`), or null when
     * the next tokens don't even look like a class name. Caller
     * decides what to do next based on whether `{` follows.
     */
    private function parseUseGroupPrefix(): ?string
    {
        $parts = [];
        if ($this->check(TokenKind::Backslash)) {
            $this->advance();
            $parts[] = '';
        }
        $head = $this->peek();
        if ($head->kind !== TokenKind::Identifier && $head->kind !== TokenKind::Keyword) {
            return null;
        }
        $this->advance();
        $parts[] = $head->lexeme;
        while ($this->check(TokenKind::Backslash)) {
            // Peek past the backslash to decide: another name segment
            // (normal FQN) vs `{` (group form). Avoid consuming the
            // backslash in the group case so the caller's match() sees
            // `{` as the very next token.
            $saved = $this->pos;
            $this->advance(); // consume `\`
            if ($this->check(TokenKind::OpenBrace)) {
                // Group form. Don't include the trailing `\` in the prefix.
                return implode('\\', $parts);
            }
            $next = $this->peek();
            if ($next->kind !== TokenKind::Identifier && $next->kind !== TokenKind::Keyword) {
                // Restore so the higher-level error path surfaces the
                // bad token in context.
                $this->pos = $saved;
                throw $this->error('expected name after \\');
            }
            $this->advance();
            $parts[] = $next->lexeme;
        }
        return implode('\\', $parts);
    }

    /**
     * Build a UseItem from a fully-resolved FQN string already in
     * hand. Mirrors parseUseItem's `as`-alias + alias-table wiring.
     */
    private function finishUseItem(string $fqn, string $kind): UseItem
    {
        $fqn = \ltrim($fqn, '\\');
        $alias = null;
        if ($this->checkKeyword('as')) {
            $this->advance();
            $aliasTok = $this->expect(TokenKind::Identifier, 'expected alias after `as`');
            $alias = $aliasTok->lexeme;
        }
        if ($kind === 'class') {
            $short = $alias ?? (\strpos($fqn, '\\') === false
                ? $fqn
                : \substr($fqn, \strrpos($fqn, '\\') + 1));
            $this->useAliases[$short] = $fqn;
        }
        return new UseItem($fqn, $alias, $kind);
    }

    /**
     * Parse one item inside a group-use body, prefixing it with the
     * outer namespace. Item form is `Name [as Alias]` — no nested
     * backslashes for now (PHP allows them but the bootstrap source
     * doesn't lean on that depth).
     */
    private function parseUseGroupItem(string $prefix, string $kind): UseItem
    {
        $tail = $this->parseClassNameLiteral();
        $tail = \ltrim($tail, '\\');
        $fqn = $prefix === '' ? $tail : ($prefix . '\\' . $tail);
        return $this->finishUseItem($fqn, $kind);
    }

    private function parseUseItem(string $kind): UseItem
    {
        $fqn = ltrim($this->parseClassNameLiteral(), '\\');
        $alias = null;
        if ($this->checkKeyword('as')) {
            $this->advance();
            $aliasTok = $this->expect(TokenKind::Identifier, 'expected alias after `as`');
            $alias = $aliasTok->lexeme;
        }
        // Wire the short name into the active alias table so
        // subsequent class references inside this file resolve to
        // the fully qualified path.
        if ($kind === 'class') {
            $short = $alias ?? (strpos($fqn, '\\') === false ? $fqn : substr($fqn, strrpos($fqn, '\\') + 1));
            $this->useAliases[$short] = $fqn;
        }
        return new UseItem($fqn, $alias, $kind);
    }

    // ── Class / interface / trait / enum ─────────────────────────────────────

    /**
     * @param AttributeNode[] $attributes
     */
    private function parseClass(array $attributes, ?string $docComment = null): Stmt
    {
        $span = $this->span();
        $isFinal = false;
        $isAbstract = false;
        $isReadonly = false;
        // Consume modifiers (final / abstract / readonly).
        while ($this->peek()->kind === TokenKind::Keyword) {
            $kw = strtolower($this->peek()->lexeme);
            if ($kw === 'final')    { $isFinal = true;   $this->advance(); continue; }
            if ($kw === 'abstract') { $isAbstract = true;$this->advance(); continue; }
            if ($kw === 'readonly') { $isReadonly = true;$this->advance(); continue; }
            break;
        }

        $kindTok = $this->advance();
        $kindKw = strtolower($kindTok->lexeme);
        if ($kindKw !== 'class' && $kindKw !== 'interface' && $kindKw !== 'trait' && $kindKw !== 'enum') {
            throw $this->error('expected class / interface / trait / enum');
        }

        $nameTok = $this->expect(TokenKind::Identifier, 'expected ' . $kindKw . ' name');
        $name = $nameTok->lexeme;
        // Qualify with the active namespace so the class table key
        // matches what references — through `use` / namespace prefix —
        // resolve to.
        if ($this->currentNamespace !== '') {
            $name = $this->currentNamespace . '\\' . $name;
        }

        $decl = $this->finishClassDecl($kindKw, $name, $attributes, $isFinal, $isAbstract, $isReadonly, $span, $docComment);
        return Stmt::class_($decl, $span);
    }

    /**
     * Parse the shared class-body surface (`extends` / `implements` / enum
     * backing / `{ members }`) into a `ClassDecl` for a header already consumed.
     * Reused by named declarations and anonymous classes.
     *
     * @param AttributeNode[] $attributes
     */
    private function finishClassDecl(string $kindKw, string $name, array $attributes, bool $isFinal, bool $isAbstract, bool $isReadonly, Span $span, ?string $docComment = null): ClassDecl
    {
        $enumBacking = null;
        if ($kindKw === 'enum' && $this->check(TokenKind::Colon)) {
            $this->advance();
            $enumBacking = $this->parseTypeHint();
        }

        $extends = [];
        $implements = [];
        if ($this->checkKeyword('extends')) {
            $this->advance();
            $extends[] = $this->parseClassName();
            while ($this->match(TokenKind::Comma)) {
                $extends[] = $this->parseClassName();
            }
        }
        if ($this->checkKeyword('implements')) {
            $this->advance();
            $implements[] = $this->parseClassName();
            while ($this->match(TokenKind::Comma)) {
                $implements[] = $this->parseClassName();
            }
        }

        $this->expect(TokenKind::OpenBrace, "expected '{' to start class body");

        $properties = [];
        $methods = [];
        $consts = [];
        $cases = [];
        $uses = [];
        /** @var array<string,string> trait name → the docblock on its `use` line
         *  (carries `@use Items<string>`, the binding for a generic trait). */
        $useDocs = [];
        $traitAdaptations = [];

        while (!$this->check(TokenKind::CloseBrace) && !$this->isAtEnd()) {
            // `$vec[$i] ?? null` doesn't shield against OOB reads on
            // an int-keyed vec: missing entries hand back whatever
            // happens to sit in the slot (or past the buffer). isset
            // checks bounds first, which is the actual semantic we
            // want — "doc comment recorded at this position?".
            $memberDoc = isset($this->docCommentByPos[(string)$this->pos])
                ? $this->docCommentByPos[(string)$this->pos]
                : null;
            $memberAttrs = $this->collectAttributes();
            $memberSpan = $this->span();
            $modifiers = $this->parseMemberModifiers();

            if ($this->checkKeyword('use')) {
                // `use Trait1, Trait2;` — record the trait names so the
                // class declaration carries them; conflict-resolution
                // braces (`use Foo { Foo::m insteadof Bar; }`) are not
                // yet supported.
                $this->advance();
                $tn = $this->parseClassName();
                $uses[] = $tn;
                if ($memberDoc !== null) { $useDocs[$tn] = $memberDoc; }
                while ($this->match(TokenKind::Comma)) {
                    $tn = $this->parseClassName();
                    $uses[] = $tn;
                    if ($memberDoc !== null) { $useDocs[$tn] = $memberDoc; }
                }
                // `use A, B { A::m insteadof B; m as x; }` conflict resolution.
                if ($this->check(TokenKind::OpenBrace)) {
                    foreach ($this->parseTraitAdaptations() as $a) { $traitAdaptations[] = $a; }
                } else {
                    $this->expect(TokenKind::Semicolon, "expected ';' after `use` in class body");
                }
                continue;
            }

            if ($this->checkKeyword('case') && $kindKw === 'enum') {
                $this->advance();
                $caseNameTok = $this->expect(TokenKind::Identifier, 'expected enum case name');
                $value = null;
                if ($this->match(TokenKind::Equals)) {
                    $value = $this->parseExpression();
                }
                $this->expect(TokenKind::Semicolon, "expected ';' after enum case");
                $cases[] = new \Parser\Ast\EnumCase($caseNameTok->lexeme, $value);
                continue;
            }

            if ($this->checkKeyword('const')) {
                $consts[] = $this->parseClassConst($modifiers, $memberAttrs, $memberSpan);
                continue;
            }

            if ($this->checkKeyword('function')) {
                $methods[] = $this->parseMethod($modifiers, $memberAttrs, $memberSpan, $memberDoc);
                continue;
            }

            // Property declaration: optional type hint, then $var [, $var ...]
            $properties = array_merge(
                $properties,
                $this->parseProperty($modifiers, $memberAttrs, $memberSpan, $memberDoc),
            );
        }

        $this->expect(TokenKind::CloseBrace, "expected '}' to close class body");

        return new ClassDecl(
            $kindKw,
            $name,
            $extends,
            $implements,
            $attributes,
            $properties,
            $methods,
            $consts,
            $cases,
            $isFinal,
            $isAbstract,
            $isReadonly,
            $enumBacking,
            $span,
            $uses,
            $traitAdaptations,
            $docComment,
            $useDocs,
        );
    }

    /**
     * Parse a trait conflict-resolution block: `{ A::m insteadof B, C; m as x;
     * m as protected; A::m as protected y; }`.
     *
     * @return \Parser\Ast\TraitAdaptation[]
     */
    private function parseTraitAdaptations(): array
    {
        $this->expect(TokenKind::OpenBrace, "expected '{' for trait adaptations");
        $out = [];
        while (!$this->check(TokenKind::CloseBrace)) {
            $first = $this->parseClassName();
            $trait = '';
            $method = $first;
            if ($this->match(TokenKind::DoubleColon)) {
                $trait = $first;
                $method = $this->advance()->lexeme;
            }
            if ($this->checkKeyword('insteadof')) {
                $this->advance();
                $exclude = [$this->parseClassName()];
                while ($this->match(TokenKind::Comma)) { $exclude[] = $this->parseClassName(); }
                $out[] = new \Parser\Ast\TraitAdaptation('insteadof', $trait, $method, $exclude, '', '');
            } elseif ($this->checkKeyword('as')) {
                $this->advance();
                $visibility = '';
                if ($this->checkKeyword('public') || $this->checkKeyword('protected') || $this->checkKeyword('private')) {
                    $visibility = \strtolower($this->advance()->lexeme);
                }
                $alias = '';
                if (!$this->check(TokenKind::Semicolon)) {
                    $alias = $this->advance()->lexeme;
                }
                $out[] = new \Parser\Ast\TraitAdaptation('as', $trait, $method, [], $visibility, $alias);
            } else {
                throw $this->error("expected 'insteadof' or 'as' in trait adaptation");
            }
            $this->expect(TokenKind::Semicolon, "expected ';' after trait adaptation");
        }
        $this->expect(TokenKind::CloseBrace, "expected '}' to close trait adaptations");
        return $out;
    }

    /**
     * @return array{visibility: string, static: bool, final: bool, abstract: bool, readonly: bool}
     */
    private function parseMemberModifiers(): MemberModifiers
    {
        $visibility = 'public';
        $static = false;
        $final = false;
        $abstract = false;
        $readonly = false;
        while ($this->peek()->kind === TokenKind::Keyword) {
            $kw = strtolower($this->peek()->lexeme);
            if ($kw === 'public' || $kw === 'protected' || $kw === 'private') {
                $this->advance();
                // PHP 8.4 asymmetric visibility: `public private(set)` scopes the
                // WRITE visibility separately. The `(set)` suffix is parsed and
                // (for now) not enforced. A `(` that is NOT `(set)` is a DNF type
                // (`public (X&Y) $p`) — leave it for the type-hint parser.
                if ($this->isSetVisibilitySuffix()) {
                    $this->advance(); // '('
                    $this->advance(); // 'set'
                    $this->advance(); // ')'
                } else {
                    $visibility = $kw;
                }
                continue;
            }
            if ($kw === 'static')   { $static   = true; $this->advance(); continue; }
            if ($kw === 'final')    { $final    = true; $this->advance(); continue; }
            if ($kw === 'abstract') { $abstract = true; $this->advance(); continue; }
            if ($kw === 'readonly') { $readonly = true; $this->advance(); continue; }
            break;
        }
        return new MemberModifiers($visibility, $static, $final, $abstract, $readonly);
    }

    /**
     * @param AttributeNode[] $attrs
     * @return PropertyDecl[]
     */
    private function parseProperty(MemberModifiers $modifiers, array $attrs, Span $span, ?string $docComment = null): array
    {
        $typeHint = null;
        // Look ahead: if next is a Variable, there is no type hint.
        if (!$this->check(TokenKind::Variable)) {
            $typeHint = $this->parseTypeHint();
        }

        $out = [];
        while (true) {
            $varTok = $this->expect(TokenKind::Variable, 'expected $property');
            $name = substr($varTok->lexeme, 1);
            $default = null;
            if ($this->match(TokenKind::Equals)) {
                $default = $this->parseExpression();
            }
            // PHP 8.4 property hooks: `$x { get => …; set(t $v) => …; }`. Only a
            // single property per declaration may carry hooks (no comma list).
            $hooks = [];
            if ($this->check(TokenKind::OpenBrace)) {
                $hooks = $this->parsePropertyHooks();
            }
            $out[] = new PropertyDecl(
                $name,
                $modifiers->visibility,
                $modifiers->isStatic,
                $modifiers->isReadonly,
                $typeHint,
                $default,
                $attrs,
                $span,
                $docComment,
                $hooks,
            );
            if ($hooks !== []) { return $out; }
            if (!$this->match(TokenKind::Comma)) { break; }
        }
        $this->expect(TokenKind::Semicolon, "expected ';' after property declaration");
        return $out;
    }

    /**
     * Parse a `{ get … set … }` property-hook block. Each hook is `get`/`set`,
     * an optional `(type $v)` value parameter (set only), and either an arrow
     * body `=> expr;` or a block body `{ … }`. A leading `&` (by-ref get) or
     * `final` modifier is accepted and ignored.
     *
     * @return \Parser\Ast\PropertyHook[]
     */
    private function parsePropertyHooks(): array
    {
        $this->expect(TokenKind::OpenBrace, "expected '{' to open property hooks");
        $hooks = [];
        while (!$this->check(TokenKind::CloseBrace)) {
            if ($this->checkKeyword('final')) { $this->advance(); }
            $this->match(TokenKind::Ampersand); // by-ref get: accepted, not modelled
            $kwTok = $this->advance();
            $kind = strtolower($kwTok->lexeme);
            if ($kind !== 'get' && $kind !== 'set') {
                throw $this->error("expected 'get' or 'set' in property hook");
            }
            $paramName = null;
            $paramType = null;
            if ($this->check(TokenKind::OpenParen)) {
                $this->advance();
                if (!$this->check(TokenKind::Variable)) {
                    $paramType = $this->parseTypeHint();
                }
                $pvar = $this->expect(TokenKind::Variable, 'expected $value in set hook');
                $paramName = substr($pvar->lexeme, 1);
                $this->expect(TokenKind::CloseParen, "expected ')' after hook parameter");
            }
            $exprBody = null;
            $blockBody = null;
            if ($this->match(TokenKind::DoubleArrow)) {
                $exprBody = $this->parseExpression();
                $this->expect(TokenKind::Semicolon, "expected ';' after hook arrow body");
            } else {
                $blockBody = $this->parseBlock();
            }
            $hooks[] = new \Parser\Ast\PropertyHook($kind, $paramName, $paramType, $exprBody, $blockBody);
        }
        $this->expect(TokenKind::CloseBrace, "expected '}' to close property hooks");
        return $hooks;
    }

    /**
     * @param AttributeNode[] $attrs
     */
    private function parseClassConst(MemberModifiers $modifiers, array $attrs, Span $span): ConstDecl
    {
        $this->advance(); // 'const'
        $typeHint = null;
        if ($this->check(TokenKind::Identifier) || $this->check(TokenKind::Keyword)) {
            $next = $this->tokens[$this->pos + 1] ?? null;
            if ($next !== null && $next->kind !== TokenKind::Equals) {
                $typeHint = $this->parseTypeHint();
            }
        }
        $nameTok = $this->expect(TokenKind::Identifier, 'expected const name');
        $this->expect(TokenKind::Equals, "expected '=' in const");
        $value = $this->parseExpression();
        $this->expect(TokenKind::Semicolon, "expected ';' after const");
        return new ConstDecl(
            $nameTok->lexeme,
            $value,
            $modifiers->visibility,
            $typeHint,
            $attrs,
            $span,
        );
    }

    /**
     * @param AttributeNode[] $attrs
     */
    private function parseMethod(MemberModifiers $modifiers, array $attrs, Span $span, ?string $docComment = null): MethodDecl
    {
        $this->advance(); // 'function'
        $returnsByRef = $this->match(TokenKind::Ampersand);
        $nameTok = $this->advance();
        if ($nameTok->kind !== TokenKind::Identifier && $nameTok->kind !== TokenKind::Keyword) {
            throw $this->error('expected method name');
        }
        $this->expect(TokenKind::OpenParen, "expected '(' after method name");
        $params = [];
        if (!$this->check(TokenKind::CloseParen)) {
            $params[] = $this->parseParam();
            while ($this->match(TokenKind::Comma)) {
                if ($this->check(TokenKind::CloseParen)) { break; }
                $params[] = $this->parseParam();
            }
        }
        $this->expect(TokenKind::CloseParen, "expected ')' after parameters");

        $returnType = null;
        if ($this->match(TokenKind::Colon)) {
            $returnType = $this->parseTypeHint();
        }

        $body = null;
        if ($this->check(TokenKind::OpenBrace)) {
            $body = $this->parseBlock();
        } else {
            $this->expect(TokenKind::Semicolon, "expected ';' on abstract / interface method");
        }
        return new MethodDecl(
            $nameTok->lexeme,
            $modifiers->visibility,
            $modifiers->isStatic,
            $modifiers->isFinal,
            $modifiers->isAbstract,
            $params,
            $returnType,
            $body,
            $attrs,
            $span,
            $returnsByRef,
            $docComment,
        );
    }

    /** @param AttributeNode[] $attrs */
    private function parseFunctionDecl(array $attrs = []): Stmt
    {
        $startSpan = $this->span();
        $docComment = isset($this->docCommentByPos[(string)$this->pos])
            ? $this->docCommentByPos[(string)$this->pos]
            : null;
        $this->advance(); // 'function'

        // PHP allows `function &name(...)` to declare a function that
        // returns by reference.
        $returnsByRef = $this->match(TokenKind::Ampersand);

        $nameTok = $this->expect(TokenKind::Identifier, 'expected function name');
        $this->expect(TokenKind::OpenParen, "expected '(' after function name");

        $params = [];
        if (!$this->check(TokenKind::CloseParen)) {
            $params[] = $this->parseParam();
            while ($this->match(TokenKind::Comma)) {
                if ($this->check(TokenKind::CloseParen)) {
                    break;
                }
                $params[] = $this->parseParam();
            }
        }
        $this->expect(TokenKind::CloseParen, "expected ')' after parameters");

        $returnType = null;
        if ($this->match(TokenKind::Colon)) {
            $returnType = $this->parseTypeHint();
        }

        $body = $this->parseBlock();
        // Qualify the function name with the active namespace so it
        // matches the call sites' parseClassName-resolved form.
        $fnName = $nameTok->lexeme;
        if ($this->currentNamespace !== '' && !str_contains($fnName, '\\')) {
            $fnName = $this->currentNamespace . '\\' . $fnName;
        }
        $decl = new FunctionDecl(
            $fnName,
            $params,
            $returnType,
            $body,
            $startSpan,
            $returnsByRef,
            $docComment,
            $attrs,
        );
        return Stmt::function_($decl, $startSpan);
    }

    private function parseParam(): Param
    {
        $startSpan = $this->span();
        $attrs = $this->collectAttributes();

        // Constructor property promotion modifiers and readonly flag.
        $promoted = '';
        $promotedReadonly = false;
        while ($this->peek()->kind === TokenKind::Keyword) {
            $kw = strtolower($this->peek()->lexeme);
            if ($kw === 'public' || $kw === 'protected' || $kw === 'private') {
                $this->advance();
                // Asymmetric visibility on a promoted param: `public private(set)`.
                // A `(` that is NOT `(set)` is a DNF type — leave it for the type
                // parser (`function __construct(public (X&Y) $p)`).
                if ($this->isSetVisibilitySuffix()) {
                    $this->advance(); // '('
                    $this->advance(); // 'set'
                    $this->advance(); // ')'
                    if ($promoted === '') { $promoted = $kw; }
                } else {
                    $promoted = $kw;
                }
                continue;
            }
            if ($kw === 'readonly') {
                $promotedReadonly = true;
                $this->advance();
                continue;
            }
            break;
        }

        $typeHint = null;
        if (
            $this->check(TokenKind::Identifier)
            || $this->check(TokenKind::Keyword)
            || $this->check(TokenKind::Question)
            || $this->check(TokenKind::Backslash)
            || $this->check(TokenKind::OpenParen)
        ) {
            $typeHint = $this->parseTypeHint();
        }

        $byRef = $this->match(TokenKind::Ampersand);
        $variadic = $this->match(TokenKind::Ellipsis);

        $varTok = $this->expect(TokenKind::Variable, 'expected $variable in parameter list');
        $name = substr($varTok->lexeme, 1);

        $default = null;
        if ($this->match(TokenKind::Equals)) {
            $default = $this->parseExpression();
        }
        return new Param(
            $name,
            $typeHint,
            $default,
            $byRef,
            $variadic,
            $promoted,
            $promotedReadonly,
            $attrs,
            $startSpan,
        );
    }

    /**
     * Parse a PHP type hint: nullable, union, intersection, qualified
     * class names, primitives, and `self` / `static` / `parent`.
     */
    private function parseTypeHint(): string
    {
        $nullable = '';
        if ($this->match(TokenKind::Question)) {
            $nullable = '?';
        }
        $parts = [$this->parseTypeAtom()];
        // Union or intersection chain.
        while (true) {
            if ($this->check(TokenKind::Pipe)) {
                $this->advance();
                $parts[] = '|';
                $parts[] = $this->parseTypeAtom();
                continue;
            }
            if ($this->check(TokenKind::Ampersand) && $this->isTypeAtomAhead()) {
                $this->advance();
                $parts[] = '&';
                $parts[] = $this->parseTypeAtom();
                continue;
            }
            break;
        }
        return $nullable . implode('', $parts);
    }

    private function isTypeAtomAhead(): bool
    {
        $t = $this->tokens[$this->pos + 1] ?? null;
        if ($t === null) { return false; }
        return $t->kind === TokenKind::Identifier
            || $t->kind === TokenKind::Keyword
            || $t->kind === TokenKind::Backslash;
    }

    private function parseTypeAtom(): string
    {
        // DNF grouping `(A&B)` — parse the inner intersection chain and keep the
        // parens in the hint string (lowerTypeHint strips them).
        if ($this->check(TokenKind::OpenParen)) {
            $this->advance();
            $inner = $this->parseTypeHint();
            $this->expect(TokenKind::CloseParen, "expected ')' to close a DNF type group");
            return '(' . $inner . ')';
        }
        if ($this->check(TokenKind::Backslash) || $this->check(TokenKind::Identifier) || $this->check(TokenKind::Keyword)) {
            $name = $this->parseClassName();
            // Generic-ish suffix: `array<T>` or `array<K, V>`. We swallow
            // the angle-bracketed text and preserve it in the returned
            // hint so callers can extract the element class.
            if ($this->check(TokenKind::Less)) {
                $this->advance();
                $depth = 1;
                $inner = '';
                while ($depth > 0) {
                    if ($this->isAtEnd()) {
                        throw $this->error("unterminated `<...>` in type hint");
                    }
                    $t = $this->peek();
                    if ($t->kind === TokenKind::Less)  { $depth++; }
                    if ($t->kind === TokenKind::Greater)  { $depth--; if ($depth === 0) { $this->advance(); break; } }
                    $inner .= $t->lexeme;
                    $this->advance();
                }
                $name .= '<' . $inner . '>';
            }
            // `Foo[]` shorthand for `array<Foo>`. We keep it as a
            // suffix string and let the compiler interpret either
            // form interchangeably.
            while ($this->check(TokenKind::OpenBracket)) {
                $next = $this->tokens[$this->pos + 1] ?? null;
                if ($next === null || $next->kind !== TokenKind::CloseBracket) {
                    break;
                }
                $this->advance();
                $this->advance();
                $name .= '[]';
            }
            return $name;
        }
        throw $this->error('expected type name');
    }

    private function parseBlock(): Block
    {
        $this->expect(TokenKind::OpenBrace, "expected '{'");
        $stmts = [];
        while (!$this->check(TokenKind::CloseBrace) && !$this->isAtEnd()) {
            $stmts[] = $this->parseStatement();
        }
        $this->expect(TokenKind::CloseBrace, "expected '}'");
        return new Block($stmts);
    }

    /**
     * Accept either a brace block or a single statement. PHP allows the
     * single-statement form for if/else/while/for/foreach bodies.
     */
    private function parseBlockOrStatement(): Block
    {
        if ($this->check(TokenKind::OpenBrace)) {
            return $this->parseBlock();
        }
        return new Block([$this->parseStatement()]);
    }

    private function parseReturn(): Stmt
    {
        $span = $this->span();
        $this->advance(); // 'return'
        $value = null;
        if (!$this->check(TokenKind::Semicolon)) {
            $value = $this->parseExpression();
        }
        $this->expect(TokenKind::Semicolon, "expected ';' after return");
        return Stmt::return_($value, $span);
    }

    private function parseEcho(): Stmt
    {
        $span = $this->span();
        $this->advance(); // 'echo'
        $exprs = [$this->parseExpression()];
        while ($this->match(TokenKind::Comma)) {
            $exprs[] = $this->parseExpression();
        }
        $this->expect(TokenKind::Semicolon, "expected ';' after echo");
        return Stmt::echo_($exprs, $span);
    }

    private function parseIf(): Stmt
    {
        $span = $this->span();
        $this->advance(); // 'if'
        $this->expect(TokenKind::OpenParen, "expected '(' after if");
        $cond = $this->parseExpression();
        $this->expect(TokenKind::CloseParen, "expected ')' after condition");
        $then = $this->parseBlockOrStatement();

        $elseifs = [];
        $else = null;
        while ($this->checkKeyword('elseif')) {
            $this->advance();
            $this->expect(TokenKind::OpenParen, "expected '(' after elseif");
            $eCond = $this->parseExpression();
            $this->expect(TokenKind::CloseParen, "expected ')' after elseif condition");
            $elseifs[] = new ElseIfArm($eCond, $this->parseBlockOrStatement());
        }
        if ($this->checkKeyword('else')) {
            $this->advance();
            // `else if` and `else { ... }` and `else statement;` all valid.
            if ($this->checkKeyword('if')) {
                $nested = $this->parseIf();
                $else = new Block([$nested]);
            } else {
                $else = $this->parseBlockOrStatement();
            }
        }
        return Stmt::if_($cond, $then, $elseifs, $else, $span);
    }

    /**
     * `declare(directive, ...)` — TOLERATED but ignored: we don't implement
     * strict_types / ticks / encoding semantics, we just must not break the
     * parse. The directive list is skipped to the matching `)`. The block form
     * `declare(...) { ... }` keeps its body; the statement form `declare(...);`
     * lowers to a no-op (`if (false) {}`).
     */
    private function parseDeclare(): Stmt
    {
        $span = $this->span();
        $this->advance(); // 'declare'
        $this->expect(TokenKind::OpenParen, "expected '(' after declare");
        // Skip directives to the matching close paren (depth-tracked).
        $depth = 1;
        while ($depth > 0 && !$this->isAtEnd()) {
            if ($this->check(TokenKind::OpenParen))  { $depth = $depth + 1; $this->advance(); continue; }
            if ($this->check(TokenKind::CloseParen)) { $depth = $depth - 1; $this->advance(); continue; }
            $this->advance();
        }
        if ($this->check(TokenKind::OpenBrace)) {
            $block = $this->parseBlock();
            return Stmt::if_(Expr::bool(true, $span), $block, [], null, $span);
        }
        if ($this->check(TokenKind::Semicolon)) { $this->advance(); }
        return Stmt::if_(Expr::bool(false, $span), new Block([]), [], null, $span);
    }

    private function parseWhile(): Stmt
    {
        $span = $this->span();
        $this->advance(); // 'while'
        $this->expect(TokenKind::OpenParen, "expected '(' after while");
        $cond = $this->parseExpression();
        $this->expect(TokenKind::CloseParen, "expected ')' after while condition");
        $body = $this->parseBlockOrStatement();
        return Stmt::while_($cond, $body, $span);
    }

    private function parseExpressionStatement(?string $docComment = null): Stmt
    {
        $span = $this->span();
        $expr = $this->parseExpression();
        $this->expect(TokenKind::Semicolon, "expected ';' after expression");
        return Stmt::expression($expr, $span, $docComment);
    }

    private function parseDoWhile(): Stmt
    {
        $span = $this->span();
        $this->advance(); // 'do'
        $body = $this->parseBlock();
        if (!$this->checkKeyword('while')) {
            throw $this->error("expected 'while' after do-block");
        }
        $this->advance();
        $this->expect(TokenKind::OpenParen, "expected '(' after while");
        $cond = $this->parseExpression();
        $this->expect(TokenKind::CloseParen, "expected ')' after condition");
        $this->expect(TokenKind::Semicolon, "expected ';' after do-while");
        return Stmt::doWhile($body, $cond, $span);
    }

    private function parseFor(): Stmt
    {
        $span = $this->span();
        $this->advance(); // 'for'
        $this->expect(TokenKind::OpenParen, "expected '(' after for");
        // init / update are comma-separated expression lists in PHP.
        $init = $this->check(TokenKind::Semicolon) ? [] : $this->parseExprList();
        $this->expect(TokenKind::Semicolon, "expected ';' in for");
        $cond = $this->check(TokenKind::Semicolon) ? null : $this->parseExpression();
        $this->expect(TokenKind::Semicolon, "expected ';' in for");
        $update = $this->check(TokenKind::CloseParen) ? [] : $this->parseExprList();
        $this->expect(TokenKind::CloseParen, "expected ')' after for clauses");
        $body = $this->parseBlockOrStatement();
        return Stmt::for_($init, $cond, $update, $body, $span);
    }

    /**
     * Parse a comma-separated list of expressions (for-init / for-update).
     *
     * @return \Parser\Ast\Expr[]
     */
    private function parseExprList(): array
    {
        $list = [$this->parseExpression()];
        while ($this->check(TokenKind::Comma)) {
            $this->advance();
            $list[] = $this->parseExpression();
        }
        return $list;
    }

    private function parseForeach(): Stmt
    {
        $span = $this->span();
        $this->advance(); // 'foreach'
        $this->expect(TokenKind::OpenParen, "expected '(' after foreach");
        $expr = $this->parseExpression();
        if (!$this->checkKeyword('as')) {
            throw $this->error("expected 'as' in foreach");
        }
        $this->advance();
        $key = null;
        $valueByRef = $this->match(TokenKind::Ampersand);
        $value = $this->parseExpression();
        if ($this->check(TokenKind::DoubleArrow)) {
            $this->advance();
            $key = $value;
            $valueByRef = $this->match(TokenKind::Ampersand);
            $value = $this->parseExpression();
        }
        $this->expect(TokenKind::CloseParen, "expected ')' after foreach clause");
        $body = $this->parseBlockOrStatement();
        return Stmt::foreach_($expr, $key, $value, $valueByRef, $body, $span);
    }

    private function parseBreakContinue(string $kind): Stmt
    {
        $span = $this->span();
        $this->advance();
        $level = 1;
        if ($this->check(TokenKind::IntLiteral)) {
            $level = (int)$this->advance()->lexeme;
        }
        $this->expect(TokenKind::Semicolon, "expected ';' after " . strtolower($kind));
        return $kind === 'Break'
            ? Stmt::break_($level, $span)
            : Stmt::continue_($level, $span);
    }

    private function parseThrow(): Stmt
    {
        $span = $this->span();
        $this->advance(); // 'throw'
        $expr = $this->parseExpression();
        $this->expect(TokenKind::Semicolon, "expected ';' after throw");
        return Stmt::throw_($expr, $span);
    }

    /**
     * Lookahead for `static $var ...` (function-scope static local
     * declaration). Returns false for `static fn`, `static function`,
     * or `static::` so those forms still parse as expressions.
     */
    private function isStaticLocalDecl(): bool
    {
        $next = $this->tokens[$this->pos + 1] ?? null;
        return $next !== null && $next->kind === TokenKind::Variable;
    }

    /**
     * Parse `static $a, $b = expr, ...;` inside a function body.
     */
    private function parseStaticLocal(): Stmt
    {
        $span = $this->span();
        $this->advance(); // 'static'
        $decls = [];
        while (true) {
            $tok = $this->expect(TokenKind::Variable, "expected variable after 'static'");
            $name = ltrim($tok->lexeme, '$');
            $default = null;
            if ($this->match(TokenKind::Equals)) {
                $default = $this->parseExpression();
            }
            $decls[] = new StaticLocalDecl($name, $default);
            if (!$this->match(TokenKind::Comma)) { break; }
        }
        $this->expect(TokenKind::Semicolon, "expected ';' after static declaration");
        return Stmt::staticLocal($decls, $span);
    }

    /**
     * Parse `global $a, $b, ...;` inside a function body.
     */
    private function parseGoto(): Stmt
    {
        $span = $this->span();
        $this->advance(); // 'goto'
        $tok = $this->expect(TokenKind::Identifier, "expected label after 'goto'");
        $this->expect(TokenKind::Semicolon, "expected ';' after goto label");
        return new \Parser\Ast\GotoStmt($tok->lexeme, $span);
    }

    private function parseGlobal(): Stmt
    {
        $span = $this->span();
        $this->advance(); // 'global'
        $names = [];
        while (true) {
            $tok = $this->expect(TokenKind::Variable, "expected variable after 'global'");
            $names[] = ltrim($tok->lexeme, '$');
            if (!$this->match(TokenKind::Comma)) { break; }
        }
        $this->expect(TokenKind::Semicolon, "expected ';' after global declaration");
        return Stmt::global_($names, $span);
    }

    private function parseTry(): Stmt
    {
        $span = $this->span();
        $this->advance(); // 'try'
        $try = $this->parseBlock();
        $catches = [];
        while ($this->checkKeyword('catch')) {
            $this->advance();
            $this->expect(TokenKind::OpenParen, "expected '(' after catch");
            $types = [$this->parseClassName()];
            while ($this->match(TokenKind::Pipe)) {
                $types[] = $this->parseClassName();
            }
            $variableName = null;
            if ($this->check(TokenKind::Variable)) {
                $variableName = substr($this->advance()->lexeme, 1);
            }
            $this->expect(TokenKind::CloseParen, "expected ')' after catch params");
            $catchBody = $this->parseBlock();
            $catches[] = new CatchClause($types, $variableName, $catchBody);
        }
        $finally = null;
        if ($this->checkKeyword('finally')) {
            $this->advance();
            $finally = $this->parseBlock();
        }
        return Stmt::tryCatch($try, $catches, $finally, $span);
    }

    private function parseSwitch(): Stmt
    {
        $span = $this->span();
        $this->advance(); // 'switch'
        $this->expect(TokenKind::OpenParen, "expected '(' after switch");
        $expr = $this->parseExpression();
        $this->expect(TokenKind::CloseParen, "expected ')' after switch expression");
        $this->expect(TokenKind::OpenBrace, "expected '{' in switch body");
        $cases = [];
        while (!$this->check(TokenKind::CloseBrace) && !$this->isAtEnd()) {
            $value = null;
            if ($this->checkKeyword('case')) {
                $this->advance();
                $value = $this->parseExpression();
            } elseif ($this->checkKeyword('default')) {
                $this->advance();
            } else {
                throw $this->error("expected 'case' or 'default' in switch body");
            }
            $this->expect(TokenKind::Colon, "expected ':' after case label");
            $body = [];
            while (
                !$this->check(TokenKind::CloseBrace)
                && !$this->checkKeyword('case')
                && !$this->checkKeyword('default')
                && !$this->isAtEnd()
            ) {
                $body[] = $this->parseStatement();
            }
            $cases[] = new SwitchArm($value, $body);
        }
        $this->expect(TokenKind::CloseBrace, "expected '}' to close switch");
        return Stmt::switch_($expr, $cases, $span);
    }

    // ── Expressions (Pratt) ──────────────────────────────────────────────────

    public function parseExpression(): Expr
    {
        return $this->parseAssign();
    }

    /**
     * Map TokenKind → assignment operator string. Returns null when the
     * current token is not an assignment.
     */
    private function assignOpFor(string $kind): ?string
    {
        if ($kind === TokenKind::PlusEquals)           { return '+=';  }
        if ($kind === TokenKind::MinusEquals)          { return '-=';  }
        if ($kind === TokenKind::StarEquals)           { return '*=';  }
        if ($kind === TokenKind::StarStarEquals)       { return '**='; }
        if ($kind === TokenKind::SlashEquals)          { return '/=';  }
        if ($kind === TokenKind::PercentEquals)        { return '%=';  }
        if ($kind === TokenKind::DotEquals)            { return '.=';  }
        if ($kind === TokenKind::AmpersandEquals)      { return '&=';  }
        if ($kind === TokenKind::PipeEquals)           { return '|=';  }
        if ($kind === TokenKind::CaretEquals)          { return '^=';  }
        if ($kind === TokenKind::ShiftLeftEquals)      { return '<<='; }
        if ($kind === TokenKind::ShiftRightEquals)     { return '>>='; }
        if ($kind === TokenKind::DoubleQuestionEquals) { return '??='; }
        return null;
    }

    private function parseAssign(): Expr
    {
        if ($this->peek()->kind === TokenKind::Keyword
            && \strtolower($this->peek()->lexeme) === 'yield') {
            return $this->parseYield();
        }
        $left = $this->parseTernary();
        $tok = $this->peek();
        if ($tok->kind === TokenKind::Equals) {
            $span = $this->span();
            $this->advance();
            // `$y = &$x` — reference-binding assignment.
            if ($this->check(TokenKind::Ampersand)) {
                $this->advance();
                $source = $this->parseAssign();
                return Expr::refAssign($left, $source, $span);
            }
            $right = $this->parseAssign(); // right-associative
            return Expr::assign($left, $right, $span);
        }
        $compoundOp = $this->assignOpFor($tok->kind);
        if ($compoundOp !== null) {
            $span = $this->span();
            $this->advance();
            $right = $this->parseAssign();
            return Expr::compoundAssign($compoundOp, $left, $right, $span);
        }
        return $left;
    }

    /**
     * `yield`, `yield $v`, `yield $k => $v`, `yield from $v`. Low
     * precedence — sits at the assignment level so `$x = yield $v` and a
     * bare `yield $v;` statement both reach here. A bare `yield` (no
     * operand) is recognised by a following expression terminator.
     */
    private function parseYield(): Expr
    {
        $span = $this->span();
        $this->advance(); // 'yield'
        // `yield from $expr` (`from` is contextual — an identifier, not a
        // keyword). Delegation lowering lands in a later stage.
        if ($this->peek()->kind === TokenKind::Identifier
            && \strtolower($this->peek()->lexeme) === 'from') {
            $this->advance();
            return Expr::yield_(null, $this->parseAssign(), true, $span);
        }
        if ($this->yieldHasNoOperand()) {
            return Expr::yield_(null, null, false, $span);
        }
        $value = $this->parseAssign();
        if ($this->peek()->kind === TokenKind::DoubleArrow) {
            $this->advance();
            return Expr::yield_($value, $this->parseAssign(), false, $span);
        }
        return Expr::yield_(null, $value, false, $span);
    }

    /** A bare `yield` is one followed by an expression terminator. */
    private function yieldHasNoOperand(): bool
    {
        $k = $this->peek()->kind;
        return $k === TokenKind::Semicolon || $k === TokenKind::CloseParen
            || $k === TokenKind::CloseBracket || $k === TokenKind::CloseBrace
            || $k === TokenKind::Comma || $k === TokenKind::Eof;
    }

    private function parseTernary(): Expr
    {
        $cond = $this->parsePipe();
        if ($this->check(TokenKind::Question)) {
            $span = $this->span();
            $this->advance();
            // Short ternary `?:` — the `then` branch is omitted.
            if ($this->check(TokenKind::Colon)) {
                $this->advance();
                $else = $this->parseAssign();
                return Expr::ternary($cond, null, $else, $span);
            }
            $then = $this->parseExpression();
            $this->expect(TokenKind::Colon, "expected ':' in ternary");
            $else = $this->parseAssign();
            return Expr::ternary($cond, $then, $else, $span);
        }
        return $cond;
    }

    /**
     * Pipe operator `$x |> $f` (PHP 8.5): left-associative, lower precedence
     * than `??`, higher than the ternary. Desugars to `$f($x)` — an Invoke of
     * the right-hand callable with the left value as its sole argument, reusing
     * the dynamic-call path (closures, first-class callables, fn-name strings).
     */
    private function parsePipe(): Expr
    {
        $left = $this->parseNullCoalesce();
        while ($this->check(TokenKind::PipeArrow)) {
            $span = $this->span();
            $this->advance();
            $right = $this->parseNullCoalesce();
            $left = $this->buildPipe($left, $right, $span);
        }
        return $left;
    }

    /**
     * Apply a pipe stage `$lhs |> $rhs`. When the right-hand callable is a
     * statically recognisable shape — a first-class callable `f(...)` /
     * `$o->m(...)` / `C::m(...)`, a string `"fn"` / `"C::m"`, or an array
     * `[$o,"m"]` / `["C","m"]` — desugar straight to the corresponding call
     * with `$lhs` as the sole argument (no closure round-trip). Anything else
     * (a closure variable, a call returning a callable) becomes a dynamic
     * Invoke of the evaluated right-hand value.
     */
    private function buildPipe(Expr $lhs, Expr $rhs, Span $span): Expr
    {
        // Subclass fields are read through type-pinned helpers: a base-`Expr`
        // read of an ambiguous field name (`object` / `args`, shared by several
        // node types) resolves to the wrong slot under the self-host build (T5).
        $k = $rhs->kind;
        if ($k === 'Call')         { return $this->pipeCall($rhs, $lhs, $span); }
        if ($k === 'MethodCall')   { return $this->pipeMethod($rhs, $lhs, $span); }
        if ($k === 'StaticCall')   { return $this->pipeStatic($rhs, $lhs, $span); }
        if ($k === 'StringLiteral') { return $this->callableFromString($this->pipeStrVal($rhs), [$lhs], $span); }
        if ($k === 'ArrayLit') {
            $arr = $this->callableFromArray($rhs, [$lhs], $span);
            if ($arr !== null) { return $arr; }
        }
        return Expr::invoke($rhs, [$lhs], $span);
    }

    private function pipeCall(\Parser\Ast\CallExpr $rhs, Expr $lhs, Span $span): Expr
    {
        if ($this->isFccArgs($rhs->args)) { return Expr::call($rhs->function, [$lhs], $span); }
        return Expr::invoke($rhs, [$lhs], $span);
    }

    private function pipeMethod(\Parser\Ast\MethodCallExpr $rhs, Expr $lhs, Span $span): Expr
    {
        if ($this->isFccArgs($rhs->args)) {
            return Expr::methodCall($rhs->object, $rhs->method, [$lhs], $rhs->nullsafe, $span);
        }
        return Expr::invoke($rhs, [$lhs], $span);
    }

    private function pipeStatic(\Parser\Ast\StaticCall $rhs, Expr $lhs, Span $span): Expr
    {
        if ($this->isFccArgs($rhs->args)) {
            return Expr::staticCall($rhs->class, $rhs->method, [$lhs], $span);
        }
        return Expr::invoke($rhs, [$lhs], $span);
    }

    private function pipeStrVal(\Parser\Ast\StringLiteral $s): string { return $s->value; }

    /** Whether an argument list is the first-class-callable marker `(...)`. */
    /** @param \Parser\Ast\Expr[] $args */
    private function isFccArgs(array $args): bool
    {
        return \count($args) === 1 && $args[0]->kind === 'Ellipsis';
    }

    /** A string callable `"fn"` / `"C::m"` applied to `$args`. */
    private function callableFromString(string $name, array $args, Span $span): Expr
    {
        $cc = \strpos($name, '::');
        if ($cc !== false) {
            return Expr::staticCall(\substr($name, 0, $cc), \substr($name, $cc + 2), $args, $span);
        }
        return Expr::call($name, $args, $span);
    }

    /** An array callable `[$o,"m"]` / `["C","m"]` applied to `$args`, or null
     *  when the literal isn't a `[receiver, methodName]` shape. */
    private function callableFromArray(\Parser\Ast\ArrayLit $arr, array $args, Span $span): ?Expr
    {
        if (\count($arr->elements) !== 2) { return null; }
        $recv = $this->elemValue($arr->elements[0]);
        $meth = $this->elemValue($arr->elements[1]);
        if ($meth->kind !== 'StringLiteral') { return null; }
        if ($recv->kind === 'StringLiteral') {
            return Expr::staticCall($this->pipeStrVal($recv), $this->pipeStrVal($meth), $args, $span);
        }
        return Expr::methodCall($recv, $this->pipeStrVal($meth), $args, false, $span);
    }

    private function elemValue(ArrayElement $e): Expr { return $e->value; }

    private function parseNullCoalesce(): Expr
    {
        $left = $this->parseOr();
        if ($this->check(TokenKind::DoubleQuestion)) {
            $span = $this->span();
            $this->advance();
            $right = $this->parseNullCoalesce(); // right-associative
            return Expr::nullCoalesce($left, $right, $span);
        }
        return $left;
    }

    private function parseOr(): Expr
    {
        $left = $this->parseAnd();
        while ($this->check(TokenKind::DoublePipe)) {
            $span = $this->span();
            $this->advance();
            $right = $this->parseAnd();
            $left = Expr::binary('||', $left, $right, $span);
        }
        return $left;
    }

    private function parseAnd(): Expr
    {
        $left = $this->parseBitOr();
        while ($this->check(TokenKind::DoubleAmpersand)) {
            $span = $this->span();
            $this->advance();
            $right = $this->parseBitOr();
            $left = Expr::binary('&&', $left, $right, $span);
        }
        return $left;
    }

    private function parseBitOr(): Expr
    {
        $left = $this->parseBitXor();
        while ($this->check(TokenKind::Pipe)) {
            $span = $this->span();
            $this->advance();
            $right = $this->parseBitXor();
            $left = Expr::binary('|', $left, $right, $span);
        }
        return $left;
    }

    private function parseBitXor(): Expr
    {
        $left = $this->parseBitAnd();
        while ($this->check(TokenKind::Caret)) {
            $span = $this->span();
            $this->advance();
            $right = $this->parseBitAnd();
            $left = Expr::binary('^', $left, $right, $span);
        }
        return $left;
    }

    private function parseBitAnd(): Expr
    {
        $left = $this->parseEquality();
        while ($this->check(TokenKind::Ampersand)) {
            $span = $this->span();
            $this->advance();
            $right = $this->parseEquality();
            $left = Expr::binary('&', $left, $right, $span);
        }
        return $left;
    }

    private function parseEquality(): Expr
    {
        $left = $this->parseComparison();
        while (true) {
            $tok = $this->peek();
            $op = null;
            if ($tok->kind === TokenKind::DoubleEquals)  { $op = '=='; }
            elseif ($tok->kind === TokenKind::TripleEquals) { $op = '==='; }
            elseif ($tok->kind === TokenKind::NotEquals)    { $op = '!='; }
            elseif ($tok->kind === TokenKind::NotIdentical) { $op = '!=='; }
            elseif ($tok->kind === TokenKind::Spaceship)    { $op = '<=>'; }
            if ($op === null) { break; }
            $span = $this->span();
            $this->advance();
            $right = $this->parseComparison();
            $left = Expr::binary($op, $left, $right, $span);
        }
        return $left;
    }

    private function parseComparison(): Expr
    {
        $left = $this->parseShift();
        while (true) {
            $tok = $this->peek();
            $op = null;
            if ($tok->kind === TokenKind::Less)          { $op = '<'; }
            elseif ($tok->kind === TokenKind::LessEquals)    { $op = '<='; }
            elseif ($tok->kind === TokenKind::Greater)       { $op = '>'; }
            elseif ($tok->kind === TokenKind::GreaterEquals) { $op = '>='; }
            if ($op === null) { break; }
            $span = $this->span();
            $this->advance();
            $right = $this->parseShift();
            $left = Expr::binary($op, $left, $right, $span);
        }
        return $left;
    }

    private function parseShift(): Expr
    {
        $left = $this->parseAddition();
        while (true) {
            $tok = $this->peek();
            $op = null;
            if ($tok->kind === TokenKind::ShiftLeft)  { $op = '<<'; }
            elseif ($tok->kind === TokenKind::ShiftRight) { $op = '>>'; }
            if ($op === null) { break; }
            $span = $this->span();
            $this->advance();
            $right = $this->parseAddition();
            $left = Expr::binary($op, $left, $right, $span);
        }
        return $left;
    }

    private function parseAddition(): Expr
    {
        $left = $this->parseMultiplication();
        while (true) {
            $tok = $this->peek();
            $op = null;
            if ($tok->kind === TokenKind::Plus)  { $op = '+'; }
            elseif ($tok->kind === TokenKind::Minus) { $op = '-'; }
            elseif ($tok->kind === TokenKind::Dot)   { $op = '.'; }
            if ($op === null) { break; }
            $span = $this->span();
            $this->advance();
            $right = $this->parseMultiplication();
            $left = Expr::binary($op, $left, $right, $span);
        }
        return $left;
    }

    private function parseMultiplication(): Expr
    {
        $left = $this->parseInstanceof();
        while (true) {
            $tok = $this->peek();
            $op = null;
            if ($tok->kind === TokenKind::Star)    { $op = '*'; }
            elseif ($tok->kind === TokenKind::Slash)   { $op = '/'; }
            elseif ($tok->kind === TokenKind::Percent) { $op = '%'; }
            if ($op === null) { break; }
            $span = $this->span();
            $this->advance();
            $right = $this->parseInstanceof();
            $left = Expr::binary($op, $left, $right, $span);
        }
        return $left;
    }

    private function parseInstanceof(): Expr
    {
        $left = $this->parsePower();
        if ($this->checkKeyword('instanceof')) {
            $span = $this->span();
            $this->advance();
            // `$x instanceof $cls` — the RHS names the class through a runtime
            // value. Lower to `is_a($x, $cls)` (identical semantics for an object
            // LHS); the is_a builtin resolves the runtime class name against the
            // module's classes. A written class name keeps the static path.
            // parsePostfix so the RHS may be a full access expression, e.g.
            // `$x instanceof $arg->typeName` (symfony) or `$x instanceof $a[0]`.
            if ($this->check(TokenKind::Variable)) {
                $classExpr = $this->parsePostfix($this->parsePrimary());
                return Expr::call('is_a', [$left, $classExpr], $span);
            }
            $class = $this->parseClassName();
            return Expr::instanceof_($left, $class, $span);
        }
        return $left;
    }

    private function parsePower(): Expr
    {
        $left = $this->parseUnary();
        if ($this->check(TokenKind::StarStar)) {
            $span = $this->span();
            $this->advance();
            $right = $this->parsePower(); // right-associative
            return Expr::binary('**', $left, $right, $span);
        }
        return $left;
    }

    private function parseUnary(): Expr
    {
        $tok = $this->peek();
        if ($tok->kind === TokenKind::Minus) {
            $span = $this->span();
            $this->advance();
            return Expr::unary('-', $this->parseUnary(), $span);
        }
        if ($tok->kind === TokenKind::Plus) {
            $span = $this->span();
            $this->advance();
            return Expr::unary('+', $this->parseUnary(), $span);
        }
        if ($tok->kind === TokenKind::Bang) {
            $span = $this->span();
            $this->advance();
            return Expr::unary('!', $this->parseUnary(), $span);
        }
        if ($tok->kind === TokenKind::Tilde) {
            $span = $this->span();
            $this->advance();
            return Expr::unary('~', $this->parseUnary(), $span);
        }
        // `@expr` error suppression — Manticore emits no diagnostics, so it is
        // a transparent no-op pass-through (same observable result as PHP here).
        if ($tok->kind === TokenKind::AtSign) {
            $this->advance();
            return $this->parseUnary();
        }
        if ($tok->kind === TokenKind::PlusPlus) {
            $span = $this->span();
            $this->advance();
            return Expr::incDec('++', true, $this->parseUnary(), $span);
        }
        if ($tok->kind === TokenKind::MinusMinus) {
            $span = $this->span();
            $this->advance();
            return Expr::incDec('--', true, $this->parseUnary(), $span);
        }
        // Cast: `(int)`, `(float)`, `(bool)`, `(string)`, `(array)`, `(object)`.
        if ($tok->kind === TokenKind::OpenParen && $this->looksLikeCast()) {
            $span = $this->span();
            $this->advance(); // '('
            $castTok = $this->advance();
            $this->expect(TokenKind::CloseParen, "expected ')' after cast");
            return Expr::cast(strtolower($castTok->lexeme), $this->parseUnary(), $span);
        }
        return $this->parsePostfix($this->parsePrimary());
    }

    private function looksLikeCast(): bool
    {
        // Need at least three tokens: `(`, type-keyword, `)`.
        $next = $this->tokens[$this->pos + 1] ?? null;
        $after = $this->tokens[$this->pos + 2] ?? null;
        if ($next === null || $after === null) { return false; }
        if ($next->kind !== TokenKind::Keyword) { return false; }
        if ($after->kind !== TokenKind::CloseParen) { return false; }
        $kw = strtolower($next->lexeme);
        return $kw === 'int' || $kw === 'integer'
            || $kw === 'float' || $kw === 'double' || $kw === 'real'
            || $kw === 'string'
            || $kw === 'bool'  || $kw === 'boolean'
            || $kw === 'array' || $kw === 'object';
    }

    /**
     * Postfix loop — after we have a primary expression, consume any
     * chain of member access, method call, static access, subscript, or
     * postfix inc/dec.
     */
    private function parsePostfix(Expr $left): Expr
    {
        while (true) {
            $tok = $this->peek();
            if ($tok->kind === TokenKind::Arrow || $tok->kind === TokenKind::NullsafeArrow) {
                $nullsafe = $tok->kind === TokenKind::NullsafeArrow;
                $span = $this->span();
                $this->advance();
                // Dynamic member name: `$obj->$name` or `$obj->{expr}`.
                if ($this->check(TokenKind::Variable) || $this->check(TokenKind::OpenBrace)) {
                    if ($this->check(TokenKind::OpenBrace)) {
                        $this->advance();
                        $nameExpr = $this->parseExpression();
                        $this->expect(TokenKind::CloseBrace, "expected '}' after dynamic member");
                    } else {
                        $vt = $this->advance();
                        $nameExpr = Expr::variable(\ltrim($vt->lexeme, '$'), $span);
                    }
                    $left = Expr::dynProp($left, $nameExpr, $nullsafe, $span);
                    continue;
                }
                $nameTok = $this->advance();
                if ($nameTok->kind !== TokenKind::Identifier && $nameTok->kind !== TokenKind::Keyword) {
                    throw $this->error('expected member name after ->');
                }
                $name = $nameTok->lexeme;
                if ($this->check(TokenKind::OpenParen)) {
                    $args = $this->parseArgList();
                    $left = Expr::methodCall($left, $name, $args, $nullsafe, $span);
                } else {
                    $left = Expr::propertyAccess($left, $name, $nullsafe, $span);
                }
                continue;
            }
            if ($tok->kind === TokenKind::OpenBracket) {
                $span = $this->span();
                $this->advance();
                $index = null;
                if (!$this->check(TokenKind::CloseBracket)) {
                    $index = $this->parseExpression();
                }
                $this->expect(TokenKind::CloseBracket, "expected ']' after subscript");
                $left = Expr::arrayAccess($left, $index, $span);
                continue;
            }
            // Indirect call: `$callable(args)` or `($expr)(args)`.
            if ($tok->kind === TokenKind::OpenParen) {
                $span = $this->span();
                $args = $this->parseArgList();
                $left = Expr::invoke($left, $args, $span);
                continue;
            }
            // `$expr::class` / `$expr::method(...)` — dynamic class
            // resolution on a variable / property chain. Builds a
            // synthetic `StaticAccess` / `StaticCall` whose class
            // string is `@dynamic` so the compiler dispatches at
            // runtime through the receiver's class id.
            if ($tok->kind === TokenKind::DoubleColon) {
                $span = $this->span();
                $this->advance();
                $memberTok = $this->advance();
                $memberName = $memberTok->lexeme;
                if ($this->check(TokenKind::OpenParen)) {
                    $args = $this->parseArgList();
                    $left = new \Parser\Ast\DynamicStaticCall($left, $memberName, $args, $span);
                } else {
                    $left = new \Parser\Ast\DynamicStaticAccess($left, $memberName, $span);
                }
                continue;
            }
            if ($tok->kind === TokenKind::PlusPlus) {
                $span = $this->span();
                $this->advance();
                $left = Expr::incDec('++', false, $left, $span);
                continue;
            }
            if ($tok->kind === TokenKind::MinusMinus) {
                $span = $this->span();
                $this->advance();
                $left = Expr::incDec('--', false, $left, $span);
                continue;
            }
            break;
        }
        return $left;
    }

    private function parseArgList(): array
    {
        $this->expect(TokenKind::OpenParen, "expected '('");
        // First-class callable syntax: `f(...)` — exactly one
        // ellipsis between the parens. Returned as the single
        // sentinel argument [Ellipsis] for the call expression
        // wrapper to recognise.
        if ($this->check(TokenKind::Ellipsis)) {
            $next = $this->tokens[$this->pos + 1] ?? null;
            if ($next !== null && $next->kind === TokenKind::CloseParen) {
                $span = $this->span();
                $this->advance(); // ...
                $this->advance(); // )
                return [Expr::ellipsis($span)];
            }
        }
        $args = [];
        if (!$this->check(TokenKind::CloseParen)) {
            $args[] = $this->parseArg();
            while ($this->match(TokenKind::Comma)) {
                if ($this->check(TokenKind::CloseParen)) {
                    break;
                }
                $args[] = $this->parseArg();
            }
        }
        $this->expect(TokenKind::CloseParen, "expected ')' after arguments");
        return $args;
    }

    /**
     * Parse a single call argument. PHP 8.0 named arguments
     * (`name: value`) are wrapped in a NamedArg Expr; positional ones
     * are returned unchanged so existing call sites stay untouched.
     */
    private function parseArg(): Expr
    {
        // Argument spread: `f(...$args)`. The `(...)` form for first-
        // class callables is handled separately in parseArgList.
        if ($this->check(TokenKind::Ellipsis)) {
            $span = $this->span();
            $this->advance();
            $value = $this->parseExpression();
            return Expr::spread($value, $span);
        }
        $head = $this->peek();
        if ($head->kind === TokenKind::Identifier || $head->kind === TokenKind::Keyword) {
            $next = $this->tokens[$this->pos + 1] ?? null;
            if ($next !== null && $next->kind === TokenKind::Colon) {
                // Disambiguate from `Class::` — Colon (single) only ever
                // means a named argument; `::` is the DoubleColon kind.
                // Keywords (e.g. `class`, `extends`) are valid argument
                // names — PHP lets reserved words land here.
                $span = $this->span();
                $name = $this->advance()->lexeme;
                $this->advance(); // ':'
                $value = $this->parseExpression();
                return Expr::namedArg($name, $value, $span);
            }
        }
        return $this->parseExpression();
    }

    /**
     * Parse a qualified class name (`Foo`, `Foo\Bar`, `\Foo\Bar`, `self`, `static`, `parent`).
     * Returns the dotted-with-backslash text.
     */
    /**
     * Parse a qualified class name literally — exactly as it appears
     * in the source, leading `\` preserved as an empty head element.
     * The `parseClassName` wrapper applies namespace / use-alias
     * resolution on top.
     */
    private function parseClassNameLiteral(): string
    {
        $parts = [];
        if ($this->check(TokenKind::Backslash)) {
            $this->advance();
            $parts[] = '';
        }
        $head = $this->peek();
        if ($head->kind === TokenKind::Identifier || $head->kind === TokenKind::Keyword) {
            $this->advance();
            $parts[] = $head->lexeme;
        } else {
            throw $this->error('expected class name');
        }
        while ($this->check(TokenKind::Backslash)) {
            $this->advance();
            $next = $this->peek();
            if ($next->kind !== TokenKind::Identifier && $next->kind !== TokenKind::Keyword) {
                throw $this->error('expected name after \\');
            }
            $this->advance();
            $parts[] = $next->lexeme;
        }
        return implode('\\', $parts);
    }

    /**
     * Parse a class reference and resolve it against the active
     * namespace and `use` alias table:
     *
     *   - `\Foo\Bar` (leading backslash) → stays absolute, `\` stripped.
     *   - `self` / `static` / `parent` → preserved verbatim.
     *   - `Bar` where `use X\Y\Bar` is in scope → `X\Y\Bar`.
     *   - `Bar\Baz` where `use X\Y\Bar` is in scope → `X\Y\Bar\Baz`.
     *   - Otherwise prefixed with the current namespace.
     */
    private function parseClassName(): string
    {
        $literal = $this->parseClassNameLiteral();
        return $this->resolveClassName($literal);
    }

    private function resolveClassName(string $literal): string
    {
        if ($literal === '') { return ''; }
        if (str_starts_with($literal, '\\')) {
            return substr($literal, 1);
        }
        $low = strtolower($literal);
        if ($low === 'self' || $low === 'static' || $low === 'parent') {
            return $literal;
        }
        // Scalar / pseudo types stay unqualified — `int` in
        // `namespace Foo` is still PHP's int, not `Foo\int`.
        // Inline checks instead of `static $scalar = [...]`: the
        // self-host build doesn't reliably re-initialise a static
        // array-default local on every call, so the lookup table
        // can come back empty and every scalar gets namespaced.
        if ($low === 'int' || $low === 'integer'
            || $low === 'bool' || $low === 'boolean'
            || $low === 'float' || $low === 'double'
            || $low === 'string' || $low === 'array'
            || $low === 'mixed' || $low === 'void' || $low === 'null'
            || $low === 'callable' || $low === 'iterable' || $low === 'object'
            || $low === 'never' || $low === 'false' || $low === 'true'
            || $low === 'numeric'
        ) {
            return $literal;
        }
        $segments = explode('\\', $literal);
        $first = $segments[0];
        if (isset($this->useAliases[$first])) {
            $segments[0] = $this->useAliases[$first];
            return implode('\\', $segments);
        }
        if ($this->currentNamespace !== '') {
            return $this->currentNamespace . '\\' . $literal;
        }
        return $literal;
    }

    private function parsePrimary(): Expr
    {
        $tok = $this->peek();
        $span = $this->span();

        if ($tok->kind === TokenKind::IntLiteral) {
            $this->advance();
            return Expr::int($this->parseIntLiteral($tok->lexeme), $span);
        }
        if ($tok->kind === TokenKind::FloatLiteral) {
            $this->advance();
            return Expr::float((float)$tok->lexeme, $span);
        }
        if ($tok->kind === TokenKind::StringLiteral) {
            $this->advance();
            if ($this->needsInterp($tok->lexeme)) {
                return $this->parseInterpolated($tok->lexeme, $span);
            }
            return Expr::string($this->unquoteString($tok->lexeme), $span);
        }
        if ($tok->kind === TokenKind::Variable) {
            $this->advance();
            return Expr::variable(substr($tok->lexeme, 1), $span);
        }
        if ($tok->kind === TokenKind::MagicConstant) {
            $this->advance();
            return Expr::magicConstant($tok->lexeme, $span);
        }
        if ($tok->kind === TokenKind::Keyword) {
            $lower = strtolower($tok->lexeme);
            if ($lower === 'true')  { $this->advance(); return Expr::bool(true,  $span); }
            if ($lower === 'false') { $this->advance(); return Expr::bool(false, $span); }
            if ($lower === 'null')  { $this->advance(); return Expr::null($span); }
            if ($lower === 'new')   { return $this->parseNew($span); }
            if ($lower === 'clone') {
                $this->advance();
                // PHP 8.5 clone-with: `clone($obj, ['p' => v])` — call shape.
                // `clone($obj)` is just a parenthesized operand (no props).
                if ($this->check(TokenKind::OpenParen)) {
                    $cargs = $this->parseArgList();
                    $cobj = $cargs[0];
                    $cwith = \count($cargs) > 1 ? $cargs[1] : null;
                    return Expr::clone_($cobj, $cwith, $span);
                }
                return Expr::clone_($this->parseUnary(), null, $span);
            }
            if ($lower === 'fn')    { return $this->parseArrowFn(false, $span); }
            if ($lower === 'function') { return $this->parseClosure(false, $span); }
            if ($lower === 'match') { return $this->parseMatchExpr($span); }
            // PHP language constructs that look like function calls but
            // accept multiple comma-separated args (isset, empty, unset,
            // list — only as expressions; the statement form for unset
            // is handled at the statement level).
            if ($lower === 'isset' || $lower === 'empty' || $lower === 'unset') {
                $this->advance();
                $args = $this->parseArgList();
                return Expr::call($lower, $args, $span);
            }
            // `list($a, , $c)` is just the parenthesised spelling of the `[$a, , $c]`
            // destructuring target — same elements, same holes, same keyed form
            // (`list('k' => $v)`). Parse it as an array literal so it shares the
            // hole handling and the one destructure lowering, exactly as
            // `array(…)` mirrors `[…]`.
            if ($lower === 'list') {
                $next = $this->tokens[$this->pos + 1] ?? null;
                if ($next !== null && $next->kind === TokenKind::OpenParen) {
                    $this->advance(); // 'list'
                    $this->advance(); // '('
                    return $this->finishArrayLiteral($span, TokenKind::CloseParen, "expected ')' to close list()");
                }
            }
            // require / include (+ _once): in whole-program AOT the target's
            // declarations are already compiled in — composer discovery and the
            // src scan pull every autoload file, including the one being required
            // — so the runtime load is redundant. Parse and DISCARD the path
            // operand, lowering to a no-op that yields null (PHP's include of a
            // file with no `return` yields int 1; nothing downstream in a compiled
            // program depends on that). A future step could resolve the path at
            // compile time and merge a genuinely external file into the build; the
            // value-returning `$x = require 'data.php'` form is not modelled yet.
            if ($lower === 'require' || $lower === 'require_once'
                    || $lower === 'include' || $lower === 'include_once') {
                $this->advance();
                $this->parseExpression(); // consume + discard the path
                return Expr::null($span);
            }
            // Legacy long-array syntax `array(a, b, k => v)` — identical to
            // `[a, b, k => v]`; still common in generated / data files.
            if ($lower === 'array') {
                $next = $this->tokens[$this->pos + 1] ?? null;
                if ($next !== null && $next->kind === TokenKind::OpenParen) {
                    $this->advance(); // 'array'
                    $this->advance(); // '('
                    return $this->finishArrayLiteral($span, TokenKind::CloseParen, "expected ')' to close array()");
                }
            }
            // `throw` is also valid as an expression in PHP 8+
            // (`$x ?? throw new Exception()`).
            if ($lower === 'throw') {
                $this->advance();
                $inner = $this->parseExpression();
                return Expr::unary('throw', $inner, $span);
            }
            if ($lower === 'static') {
                $next = $this->tokens[$this->pos + 1] ?? null;
                if ($next !== null && $next->kind === TokenKind::Keyword) {
                    $nextKw = strtolower($next->lexeme);
                    if ($nextKw === 'fn') {
                        $this->advance(); // static
                        return $this->parseArrowFn(true, $span);
                    }
                    if ($nextKw === 'function') {
                        $this->advance(); // static
                        return $this->parseClosure(true, $span);
                    }
                }
                // `static::class` / `static::method()` / `static::$prop` —
                // late static binding reference. `self`/`parent` are
                // Identifier tokens (handled below); `static` is a keyword, so
                // route it through the name/call path explicitly.
                if ($next !== null && $next->kind === TokenKind::DoubleColon) {
                    return $this->parseNameOrCall($span);
                }
            }
        }
        if ($tok->kind === TokenKind::Identifier || $tok->kind === TokenKind::Backslash) {
            // Could be a function call, a qualified name, or a static reference.
            return $this->parseNameOrCall($span);
        }
        if ($tok->kind === TokenKind::OpenBracket) {
            return $this->parseArrayLiteral($span);
        }
        if ($tok->kind === TokenKind::OpenParen) {
            $this->advance();
            $inner = $this->parseExpression();
            $this->expect(TokenKind::CloseParen, "expected ')' to close parenthesised expression");
            return $inner;
        }

        throw $this->error('unexpected token in expression: ' . $tok->kind);
    }

    private function parseArrowFn(bool $isStatic, Span $span): Expr
    {
        $this->advance(); // 'fn'
        $params = $this->parseParenParamList();
        $returnType = null;
        if ($this->match(TokenKind::Colon)) {
            $returnType = $this->parseTypeHint();
        }
        $this->expect(TokenKind::DoubleArrow, "expected '=>' in arrow function");
        $body = $this->parseExpression();
        return Expr::arrowFn($isStatic, $params, $returnType, $body, $span);
    }

    private function parseClosure(bool $isStatic, Span $span): Expr
    {
        $this->advance(); // 'function'
        $params = $this->parseParenParamList();
        $uses = [];
        if ($this->checkKeyword('use')) {
            $this->advance();
            $this->expect(TokenKind::OpenParen, "expected '(' after use");
            if (!$this->check(TokenKind::CloseParen)) {
                $uses[] = $this->parseClosureUse();
                while ($this->match(TokenKind::Comma)) {
                    if ($this->check(TokenKind::CloseParen)) { break; }
                    $uses[] = $this->parseClosureUse();
                }
            }
            $this->expect(TokenKind::CloseParen, "expected ')' after use list");
        }
        $returnType = null;
        if ($this->match(TokenKind::Colon)) {
            $returnType = $this->parseTypeHint();
        }
        $body = $this->parseBlock();
        return Expr::closure($isStatic, $params, $uses, $returnType, $body, $span);
    }

    private function parseClosureUse(): ClosureUse
    {
        $byRef = $this->match(TokenKind::Ampersand);
        $varTok = $this->expect(TokenKind::Variable, 'expected $variable in use list');
        return new ClosureUse(substr($varTok->lexeme, 1), $byRef);
    }

    /**
     * Parse `(param, param, ...)` and return the Param list. Used for
     * arrow functions and closures.
     *
     * @return Param[]
     */
    private function parseParenParamList(): array
    {
        $this->expect(TokenKind::OpenParen, "expected '('");
        $params = [];
        if (!$this->check(TokenKind::CloseParen)) {
            $params[] = $this->parseParam();
            while ($this->match(TokenKind::Comma)) {
                if ($this->check(TokenKind::CloseParen)) { break; }
                $params[] = $this->parseParam();
            }
        }
        $this->expect(TokenKind::CloseParen, "expected ')' after parameters");
        return $params;
    }

    private function parseMatchExpr(Span $span): Expr
    {
        $this->advance(); // 'match'
        $this->expect(TokenKind::OpenParen, "expected '(' after match");
        $subject = $this->parseExpression();
        $this->expect(TokenKind::CloseParen, "expected ')' after match subject");
        $this->expect(TokenKind::OpenBrace, "expected '{' to start match body");

        $arms = [];
        while (!$this->check(TokenKind::CloseBrace) && !$this->isAtEnd()) {
            $conds = null;
            if ($this->checkKeyword('default')) {
                $this->advance();
            } else {
                $conds = [$this->parseExpression()];
                while ($this->match(TokenKind::Comma)) {
                    if ($this->checkKeyword('default')
                        || $this->check(TokenKind::DoubleArrow)) {
                        break;
                    }
                    $conds[] = $this->parseExpression();
                }
            }
            $this->expect(TokenKind::DoubleArrow, "expected '=>' in match arm");
            $body = $this->parseExpression();
            $arms[] = new MatchArm($conds, $body);
            if (!$this->match(TokenKind::Comma)) {
                break;
            }
        }
        $this->expect(TokenKind::CloseBrace, "expected '}' to close match");
        return Expr::match_($subject, $arms, $span);
    }

    private function parseNew(Span $span): Expr
    {
        $this->advance(); // 'new'
        // Anonymous class: `new class(args) extends X implements Y { … }`.
        if ($this->checkKeyword('class')) {
            return $this->parseAnonClass($span);
        }
        // `new $cls(args)` — the class is named by a value, not written in the
        // source. Only the variable form: `new ($expr)(args)` would make the
        // first paren ambiguous with the argument list.
        if ($this->check(TokenKind::Variable)) {
            $classExpr = $this->parsePrimary();
            $dargs = [];
            if ($this->check(TokenKind::OpenParen)) {
                $dargs = $this->parseArgList();
            }
            return new \Parser\Ast\NewDynExpr($classExpr, $dargs, $span);
        }
        $class = $this->parseClassName();
        $args = [];
        if ($this->check(TokenKind::OpenParen)) {
            $args = $this->parseArgList();
        }
        return Expr::new_($class, $args, $span);
    }

    /**
     * `new class(ctorArgs) extends X implements Y { … }` — parse the inline
     * declaration under a synthetic unique name, hoist it to the program's
     * top-level class list (so it's registered + lowered like any class), and
     * yield `new <synthName>(ctorArgs)`.
     */
    private function parseAnonClass(Span $span): Expr
    {
        $this->advance(); // 'class'
        $args = [];
        if ($this->check(TokenKind::OpenParen)) {
            $args = $this->parseArgList();
        }
        // A valid identifier (LLVM symbol-safe) — PHP's `class@anonymous` form
        // carries an `@` that the mangler can't emit.
        $name = '__anon_class_' . (string)$this->anonClassCounter;
        $this->anonClassCounter = $this->anonClassCounter + 1;
        if ($this->currentNamespace !== '') {
            $name = $this->currentNamespace . '\\' . $name;
        }
        $decl = $this->finishClassDecl('class', $name, [], false, false, false, $span);
        $this->hoistedClasses[] = $decl;
        return Expr::new_($name, $args, $span);
    }

    private function parseArrayLiteral(Span $span): Expr
    {
        $this->advance(); // '['
        return $this->finishArrayLiteral($span, TokenKind::CloseBracket, "expected ']' to close array");
    }

    /**
     * Element loop shared by the `[ … ]` short form and the legacy `array( … )`
     * long form; the caller has already consumed the opening token. `$closeKind`
     * is the matching close token (`]` or `)`).
     */
    private function finishArrayLiteral(Span $span, string $closeKind, string $closeMsg): Expr
    {
        $elements = [];
        if (!$this->check($closeKind)) {
            $elements[] = $this->parseArrayElementOrHole($span);
            while ($this->match(TokenKind::Comma)) {
                if ($this->check($closeKind)) {
                    break;
                }
                $elements[] = $this->parseArrayElementOrHole($span);
            }
        }
        $this->expect($closeKind, $closeMsg);
        return Expr::arrayLit($elements, $span);
    }

    /**
     * A list element, or a destructuring hole. `[$a, , $c] = …` skips a position:
     * there is no hole node, so the empty slot binds to a write-only throwaway
     * variable, keeping the later targets on their indices. (A hole is only valid
     * in a destructuring target; in a value array it is invalid PHP, but a stray
     * temp there is harmless.)
     */
    private function parseArrayElementOrHole(Span $span): ArrayElement
    {
        if ($this->check(TokenKind::Comma)) {
            return new ArrayElement(null, Expr::variable('__mc_destructure_skip', $span));
        }
        return $this->parseArrayElement();
    }

    private function parseArrayElement(): ArrayElement
    {
        // Spread element: `[...$src, ...]`. Encoded as a positional
        // entry whose value is wrapped in a Spread expression so the
        // compiler can splice the source array's entries inline.
        if ($this->check(TokenKind::Ellipsis)) {
            $span = $this->span();
            $this->advance();
            $value = $this->parseExpression();
            return new ArrayElement(null, Expr::spread($value, $span));
        }
        $first = $this->parseExpression();
        if ($this->check(TokenKind::DoubleArrow)) {
            $this->advance();
            $value = $this->parseExpression();
            return new ArrayElement($first, $value);
        }
        return new ArrayElement(null, $first);
    }

    /**
     * Identifier-led primary: function call, static-class reference, or bare name.
     */
    private function parseNameOrCall(Span $span): Expr
    {
        $name = $this->parseClassName();
        $tok = $this->peek();

        if ($tok->kind === TokenKind::DoubleColon) {
            $this->advance();
            $member = $this->advance();
            // `Class::method(...)`
            if ($this->check(TokenKind::OpenParen)) {
                $args = $this->parseArgList();
                return Expr::staticCall($name, $member->lexeme, $args, $span);
            }
            // `Class::$prop` or `Class::CONST` or `Class::class`
            if ($member->kind === TokenKind::Variable) {
                return Expr::staticAccess($name, $member->lexeme, $span);
            }
            return Expr::staticAccess($name, $member->lexeme, $span);
        }

        // `name(args)` — only for unqualified names; qualified function calls
        // are rare but accepted too.
        if ($this->check(TokenKind::OpenParen)) {
            $args = $this->parseArgList();
            return Expr::call($name, $args, $span);
        }

        return Expr::identifier($name, $span);
    }

    private function parseIntLiteral(string $lex): int
    {
        $clean = str_replace('_', '', $lex);
        // Prefix detection reads directly from `$lex`, not `$clean`:
        // the self-host compiler returns 0 from
        // `\ord($clean[$i])` on the str_replace result (the byte
        // slice ends up pointing into reused heap). `$lex` is the
        // unmodified token text and works.
        if (strlen($lex) > 2) {
            $first = \ord($lex[0]);
            if ($first === 48) {  // '0'
                $second = \ord($lex[1]);
                if ($second === 120 || $second === 88) {  // 'x' 'X'
                    return $this->parseRadixInt(substr($clean, 2), 16);
                }
                if ($second === 98 || $second === 66) {   // 'b' 'B'
                    return $this->parseRadixInt(substr($clean, 2), 2);
                }
                if ($second === 111 || $second === 79) {  // 'o' 'O'
                    return $this->parseRadixInt(substr($clean, 2), 8);
                }
                // Legacy leading-zero octal (`0777`), still valid in PHP 8
                // beside `0o777` — and the spelling every file-permission
                // literal in the wild uses. A following 8/9 makes the literal
                // invalid in PHP; we fall through to decimal there rather than
                // fatal, which never mis-reads a *valid* literal.
                if ($second >= 48 && $second <= 55) {  // '0'..'7'
                    return $this->parseRadixInt(substr($clean, 1), 8);
                }
            }
        }
        return (int)$clean;
    }

    /**
     * Parse `$digits` as a base-`$radix` non-negative integer. Only
     * digits 0..9 and (for radix=16) a..f / A..F are accepted; any
     * other byte stops the scan and the prefix-so-far is returned.
     * Underscores must already be stripped by the caller.
     *
     * Instance method (not static) because the self-host build has
     * trouble dispatching `self::method()` returns through int
     * slots — `$this->method()` round-trips cleanly.
     */
    private function parseRadixInt(string $digits, int $radix): int
    {
        $n = strlen($digits);
        $acc = 0;
        $i = 0;
        while ($i < $n) {
            $c = \ord($digits[$i]);
            // Flat if-chain instead of elseif: self-host's
            // elseif lowering hides the branch type and
            // `parseRadixInt` was returning 0 from hex inputs
            // even when the byte values were right.
            $val = -1;
            if ($c >= 48 && $c <= 57) {       // '0'..'9'
                $val = $c - 48;
            }
            if ($c >= 97 && $c <= 102) {      // 'a'..'f'
                $val = $c - 97 + 10;
            }
            if ($c >= 65 && $c <= 70) {       // 'A'..'F'
                $val = $c - 65 + 10;
            }
            if ($val < 0) { break; }
            if ($val >= $radix) { break; }
            $acc = $acc * $radix + $val;
            $i = $i + 1;
        }
        return $acc;
    }

    private function unquoteString(string $lex): string
    {
        $len = strlen($lex);
        if ($len < 2) { return ''; }
        $quoteOrd = ord($lex[0]);
        $body = substr($lex, 1, $len - 2);
        if ($quoteOrd === ord("'")) {
            // Single-quoted: only \\ and \' are escape sequences.
            $body = str_replace("\\'", "'", $body);
            $body = str_replace("\\\\", "\\", $body);
            return $body;
        }
        // Double-quoted: scan and decode the full escape set (simple + \xHH +
        // octal \NNN + \v \f \e). A char scanner (not str_replace passes) so
        // variable-length escapes decode correctly and `\\` can't shadow a
        // following escape. \u{...} is still passed through literally (rare).
        $out = '';
        $n = \strlen($body);
        $i = 0;
        while ($i < $n) {
            $c = \substr($body, $i, 1);
            if ($c === '\\' && $i + 1 < $n) {
                $out .= $this->decodeEscapeSeq($body, $n, $i + 1);
                $i = $i + 1 + $this->escapeLen;
                continue;
            }
            $out .= $c;
            $i = $i + 1;
        }
        return $out;
    }

    /** Hex-digit value of `$c`, or -1. */
    private function hexDigitVal(string $c): int
    {
        $o = \ord($c);
        if ($o >= 48 && $o <= 57) { return $o - 48; }        // 0-9
        if ($o >= 97 && $o <= 102) { return $o - 97 + 10; }  // a-f
        if ($o >= 65 && $o <= 70) { return $o - 65 + 10; }   // A-F
        return -1;
    }

    private function isIdentStart(string $c): bool
    {
        $o = \ord($c);
        return ($o >= 65 && $o <= 90) || ($o >= 97 && $o <= 122) || $o === 95;
    }

    private function isIdentChar(string $c): bool
    {
        $o = \ord($c);
        return ($o >= 65 && $o <= 90) || ($o >= 97 && $o <= 122)
            || ($o >= 48 && $o <= 57) || $o === 95;
    }

    /** chars consumed AFTER the backslash by the last {@see decodeEscapeSeq}. */
    private int $escapeLen = 0;

    /**
     * Decode the double-quote escape whose backslash sits at `$i-1` (so `$i`
     * indexes the char right after it) and set {@see $escapeLen} to how many
     * chars past the backslash it spanned. Handles the simple set plus `\xHH`
     * (1-2 hex), octal `\NNN` (1-3 digits, incl. `\0`), and `\v \f \e`. An
     * unknown escape keeps both bytes (PHP behaviour). `$n` is `strlen($body)`.
     */
    private function decodeEscapeSeq(string $body, int $n, int $i): string
    {
        $this->escapeLen = 1;
        $c = \substr($body, $i, 1);
        if ($c === 'n') { return "\n"; }
        if ($c === 'r') { return "\r"; }
        if ($c === 't') { return "\t"; }
        if ($c === 'v') { return \chr(11); }
        if ($c === 'f') { return \chr(12); }
        if ($c === 'e') { return \chr(27); }
        if ($c === '"') { return '"'; }
        if ($c === '\\') { return '\\'; }
        if ($c === '$') { return '$'; }
        if ($c === 'x') {
            // 1-2 hex digits; `\x` with none is a literal backslash-x (PHP).
            $val = 0; $got = 0; $j = $i + 1;
            while ($j < $n && $got < 2) {
                $hv = $this->hexDigitVal(\substr($body, $j, 1));
                if ($hv < 0) { break; }
                $val = $val * 16 + $hv; $got = $got + 1; $j = $j + 1;
            }
            if ($got === 0) { return '\\x'; }
            $this->escapeLen = 1 + $got;
            return \chr($val & 255);
        }
        $o0 = \ord($c);
        if ($o0 >= 48 && $o0 <= 55) {
            // Octal \NNN — up to 3 octal digits (\0 is the 1-digit case).
            $val = 0; $got = 0; $j = $i;
            while ($j < $n && $got < 3) {
                $od = \ord(\substr($body, $j, 1));
                if ($od < 48 || $od > 55) { break; }
                $val = $val * 8 + ($od - 48); $got = $got + 1; $j = $j + 1;
            }
            $this->escapeLen = $got;
            return \chr($val & 255);
        }
        return '\\' . $c; // unknown escape: keep both, PHP-style
    }

    /**
     * Does a double-quoted lexeme contain interpolation (an unescaped `$name`
     * or `{$`)? Single-quoted strings never interpolate.
     */
    private function needsInterp(string $lex): bool
    {
        if (\strlen($lex) < 2 || \ord($lex[0]) !== \ord('"')) { return false; }
        $n = \strlen($lex);
        $i = 1;
        while ($i < $n - 1) {
            $c = \substr($lex, $i, 1);
            if ($c === '\\') { $i = $i + 2; continue; }
            if ($c === '$' && $i + 1 < $n - 1 && $this->isIdentStart(\substr($lex, $i + 1, 1))) { return true; }
            if ($c === '{' && $i + 1 < $n - 1 && \substr($lex, $i + 1, 1) === '$') { return true; }
            $i = $i + 1;
        }
        return false;
    }

    /**
     * Parse a double-quoted string with interpolation into a concat chain.
     * Supports PHP's simple syntax (`$v`, `$v->prop`, `$v[idx]` where idx is a
     * bareword/int/`$var`) and the complex `{$expr}` form (any expression).
     */
    private function parseInterpolated(string $lex, Span $span): Expr
    {
        $body = \substr($lex, 1, \strlen($lex) - 2);
        $n = \strlen($body);
        $parts = [];
        $lit = '';
        $i = 0;
        while ($i < $n) {
            $c = \substr($body, $i, 1);
            if ($c === '\\' && $i + 1 < $n) {
                $lit .= $this->decodeEscapeSeq($body, $n, $i + 1);
                $i = $i + 1 + $this->escapeLen;
                continue;
            }
            // Complex: {$expr}
            if ($c === '{' && $i + 1 < $n && \substr($body, $i + 1, 1) === '$') {
                if ($lit !== '') { $parts[] = Expr::string($lit, $span); $lit = ''; }
                $depth = 1;
                $j = $i + 1;
                while ($j < $n) {
                    $cj = \substr($body, $j, 1);
                    if ($cj === '{') { $depth = $depth + 1; }
                    if ($cj === '}') { $depth = $depth - 1; if ($depth === 0) { break; } }
                    $j = $j + 1;
                }
                $inner = \substr($body, $i + 1, $j - $i - 1);
                $parts[] = $this->parseSubExpr($inner, $span);
                $i = $j + 1;
                continue;
            }
            // Simple: $name [-> prop | [idx]]
            if ($c === '$' && $i + 1 < $n && $this->isIdentStart(\substr($body, $i + 1, 1))) {
                if ($lit !== '') { $parts[] = Expr::string($lit, $span); $lit = ''; }
                $i = $i + 1;
                $ns = $i;
                while ($i < $n && $this->isIdentChar(\substr($body, $i, 1))) { $i = $i + 1; }
                $expr = Expr::variable(\substr($body, $ns, $i - $ns), $span);
                if ($i + 2 < $n && \substr($body, $i, 1) === '-' && \substr($body, $i + 1, 1) === '>'
                    && $this->isIdentStart(\substr($body, $i + 2, 1))) {
                    $i = $i + 2;
                    $ps = $i;
                    while ($i < $n && $this->isIdentChar(\substr($body, $i, 1))) { $i = $i + 1; }
                    $expr = Expr::propertyAccess($expr, \substr($body, $ps, $i - $ps), false, $span);
                } elseif ($i < $n && \substr($body, $i, 1) === '[') {
                    $i = $i + 1;
                    $ks = $i;
                    while ($i < $n && \substr($body, $i, 1) !== ']') { $i = $i + 1; }
                    $key = \substr($body, $ks, $i - $ks);
                    if ($i < $n) { $i = $i + 1; }
                    $expr = Expr::arrayAccess($expr, $this->interpArrayKey($key, $span), $span);
                }
                $parts[] = Expr::cast('string', $expr, $span);
                continue;
            }
            $lit .= $c;
            $i = $i + 1;
        }
        if ($lit !== '') { $parts[] = Expr::string($lit, $span); }
        if (\count($parts) === 0) { return Expr::string('', $span); }
        $result = $parts[0];
        $k = 1;
        while ($k < \count($parts)) {
            $result = Expr::binary('.', $result, $parts[$k], $span);
            $k = $k + 1;
        }
        return $result;
    }

    /** Simple-syntax array key: `$var` → variable, all-digits → int, else bareword string. */
    private function interpArrayKey(string $key, Span $span): Expr
    {
        if (\strlen($key) > 0 && \substr($key, 0, 1) === '$') {
            return Expr::variable(\substr($key, 1), $span);
        }
        $isInt = \strlen($key) > 0;
        $ki = 0;
        $start = (\strlen($key) > 0 && \substr($key, 0, 1) === '-') ? 1 : 0;
        if ($start === 1 && \strlen($key) === 1) { $isInt = false; }
        for ($ki = $start; $ki < \strlen($key); $ki = $ki + 1) {
            $o = \ord(\substr($key, $ki, 1));
            if ($o < 48 || $o > 57) { $isInt = false; break; }
        }
        if ($isInt) { return Expr::int((int)$key, $span); }
        return Expr::string($key, $span);
    }

    /** Sub-lex + parse an interpolation `{$expr}` body into a (string)-cast expression. */
    private function parseSubExpr(string $src, Span $span): Expr
    {
        $tokens = (new Lexer())->scan($src);
        $sub = new self($tokens);
        return Expr::cast('string', $sub->parseExpression(), $span);
    }

    // ── Token helpers ────────────────────────────────────────────────────────

    private function peek(): Token
    {
        return $this->tokens[$this->pos];
    }

    /**
     * Distinguish a `(set)` asymmetric-visibility suffix from a DNF type in
     * parens (`public (X&Y) $p`): true only for the exact `( set )` shape.
     */
    private function isSetVisibilitySuffix(): bool
    {
        if (!$this->check(TokenKind::OpenParen)) { return false; }
        $inner = $this->tokens[$this->pos + 1] ?? null;
        $close = $this->tokens[$this->pos + 2] ?? null;
        return $inner !== null && \strtolower($inner->lexeme) === 'set'
            && $close !== null && $close->kind === TokenKind::CloseParen;
    }

    private function advance(): Token
    {
        $tok = $this->tokens[$this->pos];
        if (!$this->isAtEnd()) {
            $this->pos = $this->pos + 1;
        }
        return $tok;
    }

    private function isAtEnd(): bool
    {
        return $this->tokens[$this->pos]->kind === TokenKind::Eof;
    }

    private function check(string $kind): bool
    {
        return $this->tokens[$this->pos]->kind === $kind;
    }

    private function checkKeyword(string $name): bool
    {
        $tok = $this->tokens[$this->pos];
        return $tok->kind === TokenKind::Keyword && strtolower($tok->lexeme) === $name;
    }

    private function match(string $kind): bool
    {
        if ($this->check($kind)) {
            $this->advance();
            return true;
        }
        return false;
    }

    private function expect(string $kind, string $message): Token
    {
        if (!$this->check($kind)) {
            throw $this->error($message);
        }
        return $this->advance();
    }

    private function span(): Span
    {
        $tok = $this->tokens[$this->pos];
        return new Span($tok->line, $tok->column);
    }

    private function error(string $message): ParseError
    {
        $tok = $this->tokens[$this->pos];
        return new ParseError($message, $tok->line, $tok->column);
    }
}
