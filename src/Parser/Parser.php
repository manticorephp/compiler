<?php

namespace Parser;

use Lexer\Lexer;
use Lexer\RangedToken;
use Lexer\Token;
use Lexer\TokenKind;
use Parser\Ast\AttributeNode;
use Parser\Ast\Block;
use Parser\Ast\ClassDecl;
use Parser\Ast\ConstDecl;
use Parser\Ast\Expr;
use Parser\Ast\FunctionDecl;
use Parser\Ast\MethodDecl;
use Parser\Ast\NameRef;
use Parser\Ast\Param;
use Parser\Ast\Program;
use Parser\Ast\PropertyDecl;
use Parser\Ast\RangedSpan;
use Parser\Ast\Span;
use Parser\Ast\ArrayElement;
use Parser\Ast\CatchClause;
use Parser\Ast\ClosureUse;
use Parser\Ast\ElseIfArm;
use Parser\Ast\MatchArm;
use Parser\Ast\LazyBody;
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
 * The AST shape is stable enough to diff: `Parser\Dump::program()` is the
 * regression format the parser tests compare against.
 */
final class Parser
{
    /** @var Token[] */
    private array $tokens = [];
    private int $pos = 0;
    private string $sourceText = '';
    /** @var int[] line number (1-based) → byte offset */
    private array $lineOffsets = [];
    private bool $lazyBodies = false;

    /** 1 in tolerant mode (an int, not a bool: see Lexer::scan()). */
    private int $tolerant = 0;

    /** 1 when the written-name table is collected (an int, not a bool: see Lexer::scan()). */
    private int $collectNames = 0;
    private int $declNames = 0;

    /** 1 when the tokens are RangedTokens (ranges or names on); only then are token ends read. */
    private int $ends = 0;

    /**
     * Written names by "line:column:kind" (a rewound re-parse overwrites its own entry).
     * @var array<string, NameRef>
     */
    private array $names = [];

    /** Range of the name parseClassNameLiteral() read last (collectNames only). */
    private int $nmLine = 0;
    private int $nmColumn = 0;
    private int $nmEndLine = 0;
    private int $nmEndColumn = 0;

    /** @var ParseError[] errors recorded while recovering (tolerant mode) */
    private array $errors = [];

    /** Current statement + expression nesting (tolerant mode; see MAX_DEPTH). */
    private int $depth = 0;

    /** Tolerant mode: an error beyond the 100th was dropped; parseTolerant appends the one "too many errors" entry. */
    private int $capped = 0;

    /** record() calls so far, dropped duplicates included: a loop sees whether an iteration reported anything. */
    private int $recorded = 0;

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
     * Lowercased alias → FQN of `use function X as alias;`.
     *
     * @var array<string, string>
     */
    private array $useFnAliases = [];

    /**
     * `use const X\Y as Z` aliases (case-sensitive). Only the name table reads it.
     *
     * @var array<string, string>
     */
    private array $useConstAliases = [];

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
    /**
     * $file is the source's path, used ONLY to resolve `__FILE__`/`__DIR__` — php
     * folds those at compile time, and by the time statements reach lowering they
     * have been flattened across every file of the build, so the identity has to be
     * captured HERE. Empty (stdin, eval-like sources, the prelude blob) leaves both
     * as '', which is also what php reports for code with no file of its own.
     */
    public static function parseSource(
        string $source,
        string $file = '',
        bool $lazyBodies = false,
        bool $ranges = false,
        bool $names = false,
    ): Program {
        // Constructing the parser consumes the lexer array into its filtered
        // stream. Keep the original token-array temporary out of the
        // parseProgram() lifetime: on large files it otherwise retains every
        // token alongside the parser's filtered array and the AST being built.
        $p = new self((new Lexer())->scan($source, false, false, $ranges || $names), $source, $file, $lazyBodies);
        $p->ranges = $ranges ? 1 : 0;
        $p->ends = ($ranges || $names) ? 1 : 0;
        $p->collectNames = $names ? 1 : 0;
        return $p->parseProgram();
    }

    public static function parseTolerant(string $source, string $file = '', bool $ranges = true, bool $names = true, int $declNames = 0): ParseResult
    {
        $lexer = new Lexer();
        $errors = [];
        $program = new Program([]);
        $p = null;
        $thrown = null;
        try {
            $tokens = $lexer->scan($source, false, true, $ranges || $names);
            $p = new self($tokens, $source, $file, false);
            $p->ranges = $ranges ? 1 : 0;
            $p->ends = ($ranges || $names) ? 1 : 0;
            $p->tolerant = 1;
            $p->collectNames = $names ? 1 : 0;
            $p->declNames = $declNames;
            // Lexer errors are recorded first: the cap keeps the first 100 in discovery order (not by position), so lexer errors alone can fill it and drop the parser errors.
            foreach ($lexer->errors as $le) { $p->record($le); }
            $program = $p->parseProgram();
        } catch (ParseError $e) {
            if ($p !== null) { $p->record($e); }
            else { $errors[] = $e; }
        } catch (\Throwable $e) {
            $thrown = new ParseError($e->getMessage(), 1, 1, 1, 1);
        }
        if ($p !== null) { $errors = $p->errors; }
        if ($thrown !== null) { $errors[] = $thrown; }
        $capped = $p !== null && $p->capped === 1;
        for ($i = 1; $i < \count($errors); $i = $i + 1) {
            $e = $errors[$i];
            $j = $i - 1;
            while ($j >= 0 && ($errors[$j]->errLine > $e->errLine
                || ($errors[$j]->errLine === $e->errLine && $errors[$j]->column > $e->column))) {
                $errors[$j + 1] = $errors[$j];
                $j = $j - 1;
            }
            $errors[$j + 1] = $e;
        }
        // The final "too many errors" entry carries the last kept error's position.
        if ($capped) {
            $l = $errors[\count($errors) - 1];
            $errors[] = new ParseError('too many errors', $l->errLine, $l->column, $l->endLine, $l->endColumn);
        }
        return new ParseResult($program, $errors);
    }

    /** Materialize one deferred function/method body and release its source copy. */
    public static function materializeLazyBody(LazyBody $lazy): Block
    {
        $raw = substr($lazy->source, $lazy->start, $lazy->end - $lazy->start);
        $linePad = str_repeat("\n", $lazy->line - 1);
        $wrapped = $linePad . "<?php function __manticore_lazy_body() " . $raw;
        try {
            $p = new self((new Lexer())->scan($wrapped), $wrapped, $lazy->file, false);
            $p->sourceFile = $lazy->file;
            $p->currentNamespace = $lazy->namespace;
            $p->useAliases = $lazy->useAliases;
            $p->useFnAliases = $lazy->useFnAliases;
            $program = $p->parseProgram();
            $lazy->source = '';
            foreach ($program->statements as $stmt) {
                if ($stmt->kind === 'Function' && $stmt->decl->body !== null) {
                    return $stmt->decl->body;
                }
            }
            throw new \RuntimeException('manticore: failed to materialize lazy body');
        } catch (\Throwable $e) {
            $preview = substr(str_replace(["\n", "\r"], ' ', $raw), 0, 120);
            throw new \RuntimeException('lazy body ' . $lazy->file . ':' . (string)$lazy->line
                . ' [' . $preview . ']: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Path of the file these tokens came from, for `__FILE__`/`__DIR__`. Public so
     * {@see parseSource()} can set it without a wider ctor change; '' when the source
     * has no file (stdin, the prelude blob).
     */
    public string $sourceFile = '';

    /** dirname($sourceFile) with no trailing slash, '' when there is no file. */
    private function sourceDir(): string
    {
        if ($this->sourceFile === '') { return ''; }
        $pos = \strrpos($this->sourceFile, '/');
        if ($pos === false) { return '.'; }
        if ($pos === 0) { return '/'; }
        return \substr($this->sourceFile, 0, $pos);
    }

    /**
     * @param Token[] $tokens
     */
    public function __construct(
        array $tokens,
        string $source = '',
        string $file = '',
        bool $lazyBodies = false,
    ) {
        $this->sourceText = $source;
        $this->sourceFile = $file;
        $this->lazyBodies = $lazyBodies;
        $this->lineOffsets = [0];
        for ($i = 0; $i < strlen($source); $i = $i + 1) {
            if ($source[$i] === "\n") { $this->lineOffsets[] = $i + 1; }
        }
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
        $this->spanPos = -1;
        $this->spanMemo = null;
    }

    public function parseProgram(): Program
    {
        if ($this->check(TokenKind::OpenTag)) {
            $this->advance();
        }

        $stmts = [];
        while (!$this->isAtEnd()) {
            if ($this->check(TokenKind::CloseTag) || $this->check(TokenKind::OpenTag)) {
                $this->advance();
                continue;
            }
            if ($this->match(TokenKind::Semicolon)) { continue; }   // empty statement
            $stmts[] = $this->tolerant === 1 ? $this->parseStatementOrRecover() : $this->parseStatement();
        }
        // Append hoisted anonymous classes as top-level declarations (order is
        // irrelevant — the class pre-pass scans every class statement).
        foreach ($this->hoistedClasses as $decl) {
            $stmts[] = Stmt::class_($decl, $decl->span);
        }
        // Capture the file's final namespace + use-aliases context so
        // compile-time short-name resolution can map `Foo` → `Use\Foo`.
        return new Program($stmts, $this->currentNamespace, $this->useAliases, $this->allDocComments, $this->collectNames === 1 ? \array_values($this->names) : []);
    }

    // ── Statements ───────────────────────────────────────────────────────────

    private function parseStatement(): Stmt
    {
        // Literal output, and php's short echo tag. Handled here rather than in
        // parseProgram alone because a template interleaves them with the
        // ALTERNATIVE syntax — an open tag, `foreach (…):`, a close tag, some
        // markup, then a tag pair around `endforeach;` — so both of them appear
        // wherever a statement can, not only at the top level.
        // (Spelling the tags out here would end THIS file's php block.)
        if ($this->check(TokenKind::InlineHtml)) {
            $span = $this->span();
            $tok = $this->advance();
            // php echoes it verbatim: one echo of one string constant, and no
            // escape processing of any kind applies to it.
            $end = $this->end($span);
            return Stmt::echo_([Expr::string($tok->lexeme, $end)], $end);
        }
        if ($this->check(TokenKind::OpenTagEcho)) {
            $span = $this->span();
            $this->advance();
            // The short echo tag takes a comma-separated expression LIST, like
            // `echo` does, and its `;` is optional before the close tag.
            $args = [$this->parseExpression()];
            while ($this->match(TokenKind::Comma)) { $args[] = $this->parseExpression(); }
            $this->match(TokenKind::Semicolon);
            return Stmt::echo_($args, $this->end($span));
        }
        // A tag straddling a statement boundary carries no statement of its own;
        // a close tag also ends a statement, the way a `;` does. There is no
        // no-op statement to return, so an empty echo is the one that costs
        // nothing (the emitter drops a zero-expression echo).
        if ($this->check(TokenKind::CloseTag) || $this->check(TokenKind::OpenTag)) {
            $span = $this->span();
            $this->advance();
            return Stmt::echo_([], $this->end($span));
        }
        // A doc comment at the head of a statement (inline `/** @var T $x */`
        // before an assignment) — captured before anything advances so an
        // expression statement can carry it for local-type seeding.
        $stmtDoc = isset($this->docCommentByPos[(string)$this->pos])
            ? $this->docCommentByPos[(string)$this->pos]
            : null;
        // Collect leading attributes; they apply to whatever declaration
        // follows (function, class, or property at the head of a class
        // body — but here only function and class apply).
        $firstTok = $this->peek();
        $attrs = $this->collectAttributes();

        $tok = $this->peek();

        if ($tok->kind === TokenKind::Keyword) {
            $kw = strtolower($tok->lexeme);
            if ($kw === 'namespace' && !$this->namespaceRelativeAhead()) return $this->parseNamespace();
            if ($kw === 'use')       return $this->parseUseDecl();
            if ($kw === 'class' || $kw === 'final' || $kw === 'abstract' || $kw === 'readonly') {
                if ($kw === 'class' || $this->isClassModifierFollowedByClass()) {
                    return $this->parseClass($attrs, $stmtDoc, $firstTok);
                }
            }
            if ($kw === 'interface') return $this->parseClass($attrs, $stmtDoc, $firstTok);
            if ($kw === 'trait')     return $this->parseClass($attrs, $stmtDoc, $firstTok);
            if ($kw === 'enum')      return $this->parseClass($attrs, $stmtDoc, $firstTok);
            if ($kw === 'function')  return $this->parseFunctionDecl($attrs, $firstTok);
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
            if ($kw === 'const')     return $this->parseTopLevelConst($attrs);
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
                return new \Parser\Ast\LabelStmt($name, $this->end($span));
            }
        }

        // Bare block.
        if ($this->check(TokenKind::OpenBrace)) {
            $span = $this->span();
            $block = $this->parseBlock();
            return Stmt::if_(Expr::bool(true, $this->emptyAt($span)), $block, [], null, $this->end($span));
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
                $nameTok = $this->peek();
                $name = $this->parseClassName();
                $args = [];
                if ($this->check(TokenKind::OpenParen)) {
                    $args = $this->parseArgList();
                }
                $out[] = new AttributeNode($name, $args, $this->endFrom($span, $nameTok));
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
            if ($this->collectNames === 1) { $this->noteName($name, ltrim($name, '\\'), 'namespace', false); }
        }
        $name = ltrim($name, '\\');
        if ($this->check(TokenKind::OpenBrace)) {
            $prevNs = $this->currentNamespace;
            $prevUses = $this->useAliases;
            $prevFnUses = $this->useFnAliases;
            $prevConstUses = $this->useConstAliases;
            $this->currentNamespace = $name;
            $this->useAliases = [];
            $this->useFnAliases = [];
            $this->useConstAliases = [];
            try {
                $body = $this->parseBlock();
            } finally {
                $this->currentNamespace = $prevNs;
                $this->useAliases = $prevUses;
                $this->useFnAliases = $prevFnUses;
                $this->useConstAliases = $prevConstUses;
            }
            return Stmt::namespace_($name, $body, $this->end($span));
        }
        $this->expect(TokenKind::Semicolon, "expected ';' after namespace name");
        // Non-braced namespace declaration owns everything until the
        // next `namespace`/EOF, so we just install the context here
        // and let later `parseUseDecl` calls layer aliases onto it.
        $this->currentNamespace = $name;
        $this->useAliases = [];
        $this->useFnAliases = [];
        $this->useConstAliases = [];
        return Stmt::namespace_($name, null, $this->end($span));
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
        $firstItem = $this->peek();
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
            $items[] = $this->finishUseItem($prefix ?? '', $kind, $firstItem);
            while ($this->match(TokenKind::Comma)) {
                $items[] = $this->parseUseItem($kind);
            }
        }
        $this->expect(TokenKind::Semicolon, "expected ';' after use");
        return Stmt::useDecl($items, $this->end($span));
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
        $startTok = $this->peek();
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
            $endLine = $this->prevEndLine;
            $endColumn = $this->prevEndColumn;
            $this->advance(); // consume `\`
            if ($this->check(TokenKind::OpenBrace)) {
                // Group form. Don't include the trailing `\` in the prefix.
                $prefix = implode('\\', $parts);
                if ($this->collectNames === 1) {
                    $this->setNameRange($startTok, $endLine, $endColumn);
                    $this->noteName($prefix, ltrim($prefix, '\\'), 'namespace', false);
                }
                return $prefix;
            }
            $next = $this->peek();
            if ($next->kind !== TokenKind::Identifier && $next->kind !== TokenKind::Keyword) {
                // Restore so the higher-level error path surfaces the
                // bad token in context.
                $this->rewind($saved);
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
    private function finishUseItem(string $fqn, string $kind, Token $first, ?string $written = null, ?Token $nameFirst = null): UseItem
    {
        if ($this->collectNames === 1 && $fqn !== '') {
            $this->setNameRange($nameFirst ?? $first, $this->prevEndLine, $this->prevEndColumn);
            $this->noteName($written ?? $fqn, \ltrim($fqn, '\\'), 'use', false, $this->useRole($kind));
        }
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
        } elseif ($kind === "function") {
            $this->useFnAliases[\strtolower($alias ?? (\strpos($fqn, "\\") === false ? $fqn : \substr($fqn, \strrpos($fqn, "\\") + 1)))] = $fqn;
        } elseif ($kind === 'const') {
            $this->useConstAliases[$alias ?? (\strpos($fqn, "\\") === false ? $fqn : \substr($fqn, \strrpos($fqn, "\\") + 1))] = $fqn;
        }
        return new UseItem($fqn, $alias, $kind, $this->listSpan($first, $this->prevEndLine, $this->prevEndColumn));
    }

    /**
     * Parse one item inside a group-use body, prefixing it with the
     * outer namespace. Item form is `Name [as Alias]` — no nested
     * backslashes for now (PHP allows them but the bootstrap source
     * doesn't lean on that depth).
     */
    private function parseUseGroupItem(string $prefix, string $kind): UseItem
    {
        $first = $this->peek();
        if ($kind === 'class') {
            if ($this->checkKeyword('function')) { $this->advance(); $kind = 'function'; }
            elseif ($this->checkKeyword('const')) { $this->advance(); $kind = 'const'; }
        }
        $nameFirst = $this->peek();
        $tail = $this->parseClassNameLiteral();
        $written = $tail;
        $tail = \ltrim($tail, '\\');
        $fqn = $prefix === '' ? $tail : ($prefix . '\\' . $tail);
        return $this->finishUseItem($fqn, $kind, $first, $written, $nameFirst);
    }

    private function parseUseItem(string $kind): UseItem
    {
        $first = $this->peek();
        $written = $this->parseClassNameLiteral();
        if ($this->collectNames === 1) { $this->noteName($written, ltrim($written, '\\'), 'use', false, $this->useRole($kind)); }
        $fqn = ltrim($written, '\\');
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
        } elseif ($kind === "function") {
            $this->useFnAliases[\strtolower($alias ?? (\strpos($fqn, "\\") === false ? $fqn : \substr($fqn, \strrpos($fqn, "\\") + 1)))] = $fqn;
        } elseif ($kind === 'const') {
            $this->useConstAliases[$alias ?? (\strpos($fqn, "\\") === false ? $fqn : \substr($fqn, \strrpos($fqn, "\\") + 1))] = $fqn;
        }
        return new UseItem($fqn, $alias, $kind, $this->listSpan($first, $this->prevEndLine, $this->prevEndColumn));
    }

    // ── Class / interface / trait / enum ─────────────────────────────────────

    /**
     * @param AttributeNode[] $attributes
     */
    private function parseClass(array $attributes, ?string $docComment = null, ?Token $first = null): Stmt
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
        $this->noteDecl($nameTok, $name, 'class', 1);

        $decl = $this->finishClassDecl($kindKw, $name, $attributes, $isFinal, $isAbstract, $isReadonly, $span, $docComment, $first);
        return Stmt::class_($decl, $decl->span);
    }

    /**
     * Parse the shared class-body surface (`extends` / `implements` / enum
     * backing / `{ members }`) into a `ClassDecl` for a header already consumed.
     * Reused by named declarations and anonymous classes.
     *
     * @param AttributeNode[] $attributes
     */
    private function finishClassDecl(string $kindKw, string $name, array $attributes, bool $isFinal, bool $isAbstract, bool $isReadonly, Span $span, ?string $docComment = null, ?Token $first = null): ClassDecl
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

        $acc = new ClassMembers();
        if ($this->tolerant === 1) {
            $this->parseClassMembersTolerant($kindKw, $acc);
        } else {
            while (!$this->check(TokenKind::CloseBrace) && !$this->isAtEnd()) {
                $this->parseClassMember($kindKw, $acc);
            }
        }

        $this->expect(TokenKind::CloseBrace, "expected '}' to close class body");

        return new ClassDecl(
            $kindKw,
            $name,
            $extends,
            $implements,
            $attributes,
            $acc->properties,
            $acc->methods,
            $acc->consts,
            $acc->cases,
            $isFinal,
            $isAbstract,
            $isReadonly,
            $enumBacking,
            $first === null ? $this->end($span) : $this->endFrom($span, $first),
            $acc->uses,
            $acc->traitAdaptations,
            $docComment,
            $acc->useDocs,
            $acc->errorMembers,
        );
    }

    /** Parse one class member into $acc (strict and tolerant share it). */
    private function parseClassMember(string $kindKw, ClassMembers $acc): void
    {
        // `$vec[$i] ?? null` doesn't shield against OOB reads on
        // an int-keyed vec: missing entries hand back whatever
        // happens to sit in the slot (or past the buffer). isset
        // checks bounds first, which is the actual semantic we
        // want — "doc comment recorded at this position?".
        $memberDoc = isset($this->docCommentByPos[(string)$this->pos])
            ? $this->docCommentByPos[(string)$this->pos]
            : null;
        $memberFirst = $this->peek();
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
            $acc->uses[] = $tn;
            if ($memberDoc !== null) { $acc->useDocs[$tn] = $memberDoc; }
            while ($this->match(TokenKind::Comma)) {
                $tn = $this->parseClassName();
                $acc->uses[] = $tn;
                if ($memberDoc !== null) { $acc->useDocs[$tn] = $memberDoc; }
            }
            // `use A, B { A::m insteadof B; m as x; }` conflict resolution.
            if ($this->check(TokenKind::OpenBrace)) {
                foreach ($this->parseTraitAdaptations() as $a) { $acc->traitAdaptations[] = $a; }
            } else {
                $this->expect(TokenKind::Semicolon, "expected ';' after `use` in class body");
            }
            return;
        }

        if ($this->checkKeyword('case') && $kindKw === 'enum') {
            $this->advance();
            $caseNameTok = $this->expectMemberName('expected enum case name');
            $this->noteDecl($caseNameTok, $caseNameTok->lexeme, 'member', 1);
            $value = null;
            if ($this->match(TokenKind::Equals)) {
                $value = $this->parseExpression();
            }
            $this->expect(TokenKind::Semicolon, "expected ';' after enum case");
            $acc->cases[] = new \Parser\Ast\EnumCase($caseNameTok->lexeme, $value,
                $memberAttrs, $this->endFrom($memberSpan, $memberFirst));
            return;
        }

        if ($this->checkKeyword('const')) {
            foreach ($this->parseClassConsts($modifiers, $memberAttrs, $memberSpan, $memberFirst, $memberDoc) as $const) {
                $acc->consts[] = $const;
            }
            return;
        }

        if ($this->checkKeyword('function')) {
            $acc->methods[] = $this->parseMethod($modifiers, $memberAttrs, $memberSpan, $memberDoc, $memberFirst);
            return;
        }

        // Property declaration: optional type hint, then $var [, $var ...]
        $acc->properties = array_merge(
            $acc->properties,
            $this->parseProperty($modifiers, $memberAttrs, $memberSpan, $memberDoc, $memberFirst),
        );
    }

    /**
     * Tolerant member loop: a member that cannot be parsed is recorded and
     * skipped to the next member keyword, `;` or the body's `}`; the member is
     * omitted (the member lists are typed, there is no placeholder).
     */
    private function parseClassMembersTolerant(string $kindKw, ClassMembers $acc): void
    {
        $iterPos = -1;
        while (!$this->check(TokenKind::CloseBrace) && !$this->isAtEnd()) {
            if ($this->pos === $iterPos) {
                $this->record($this->error('unexpected token: ' . $this->tokens[$this->pos]->kind));
                $this->advance();
                continue;
            }
            $iterPos = $this->pos;
            $memberStart = $this->pos;
            $memberAnchor = $this->span();
            $memberDepth = $this->depth;
            try {
                $this->parseClassMember($kindKw, $acc);
            } catch (ParseError $e) {
                $this->depth = $memberDepth;
                $this->record($e);
                $this->skipToSync($memberStart, true);
                $acc->errorMembers[] = new \Parser\Ast\ErrorMember($this->endFrom($memberAnchor, $this->tokens[$memberStart]), $this->pos - $memberStart);
            }
        }
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
            if ($this->tolerant === 1 && $this->isAtEnd()) { break; }
            // The leading name is a TRAIT only when `::` follows it. Bare, it is
            // a METHOD name — and parseClassName resolves what it reads against
            // the current namespace and `use` aliases, which for a method name
            // is wrong: inside `namespace Symfony\Component\Cache\Adapter`,
            // `doClear as private doClearCache;` recorded its method as
            // `Symfony\Component\Cache\Adapter\doClear`, so no trait ever matched
            // it and the alias got no body — an undefined symbol at link time.
            // Invisible from a global-namespace test, which is why it survived.
            $adaptFirst = $this->peek();
            $rawFirst = $adaptFirst->lexeme;
            $firstLiteral = $this->parseClassNameLiteral();
            $first = $this->resolveClassName($firstLiteral);
            $trait = '';
            $method = $rawFirst;
            if ($this->match(TokenKind::DoubleColon)) {
                if ($this->collectNames === 1) { $this->noteClass($firstLiteral, $first); }
                $trait = $first;
                $method = $this->advance()->lexeme;
            }
            if ($this->checkKeyword('insteadof')) {
                $this->advance();
                $exclude = [$this->parseClassName()];
                while ($this->match(TokenKind::Comma)) { $exclude[] = $this->parseClassName(); }
                $adaptKind = 'insteadof';
                $adaptExclude = $exclude;
                $adaptVis = '';
                $adaptAlias = '';
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
                $adaptKind = 'as';
                $adaptExclude = [];
                $adaptVis = $visibility;
                $adaptAlias = $alias;
            } else {
                throw $this->error("expected 'insteadof' or 'as' in trait adaptation");
            }
            $this->expect(TokenKind::Semicolon, "expected ';' after trait adaptation");
            $out[] = new \Parser\Ast\TraitAdaptation($adaptKind, $trait, $method, $adaptExclude, $adaptVis, $adaptAlias, $this->listSpan($adaptFirst, $this->prevEndLine, $this->prevEndColumn));
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
    private function parseProperty(MemberModifiers $modifiers, array $attrs, Span $span, ?string $docComment = null, ?Token $first = null): array
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
            $this->noteDecl($varTok, $name, 'member', 2);
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
            $last = $hooks !== [] || !$this->check(TokenKind::Comma);
            if ($last && $hooks === []) {
                $this->expect(TokenKind::Semicolon, "expected ';' after property declaration");
            }
            $out[] = new PropertyDecl(
                $name,
                $modifiers->visibility,
                $modifiers->isStatic,
                $modifiers->isReadonly,
                $typeHint,
                $default,
                $attrs,
                $out === [] && $first !== null ? $this->endFrom($span, $first) : $this->endFrom($span, $varTok),
                $docComment,
                $hooks,
            );
            if ($last) { return $out; }
            $this->advance(); // ','
        }
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
            if ($this->tolerant === 1 && $this->isAtEnd()) { break; }
            $hookFirst = $this->peek();
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
            $hooks[] = new \Parser\Ast\PropertyHook($kind, $paramName, $paramType, $exprBody, $blockBody, $this->listSpan($hookFirst, $this->prevEndLine, $this->prevEndColumn));
        }
        $this->expect(TokenKind::CloseBrace, "expected '}' to close property hooks");
        return $hooks;
    }

    /**
     * Parse one PHP class-constant declaration, which may contain multiple
     * comma-separated name/value pairs. PHP permits both `const A = 1;` and
     * `const A = 1, B = 2;`; the AST deliberately keeps one ConstDecl per
     * pair because all MIR consumers already operate on that representation.
     *
     * @param AttributeNode[] $attrs
     * @return ConstDecl[]
     */
    private function parseClassConsts(MemberModifiers $modifiers, array $attrs, Span $span, ?Token $first = null, ?string $docComment = null): array
    {
        $this->advance(); // 'const'
        $typeHint = null;
        if (
            $this->check(TokenKind::Question)
            || $this->check(TokenKind::Backslash)
            || $this->check(TokenKind::OpenParen)
        ) {
            $typeHint = $this->parseTypeHint();
        } elseif ($this->check(TokenKind::Identifier) || $this->check(TokenKind::Keyword)) {
            $next = $this->tokens[$this->pos + 1] ?? null;
            if ($next !== null && $next->kind !== TokenKind::Equals) {
                $typeHint = $this->parseTypeHint();
            }
        }
        $consts = [];
        while (true) {
            $nameTok = $this->expectMemberName('expected const name');
            $this->noteDecl($nameTok, $nameTok->lexeme, 'member', 2);
            $this->expect(TokenKind::Equals, "expected '=' in const");
            $value = $this->parseExpression();
            $last = !$this->check(TokenKind::Comma);
            if ($last) {
                $this->expect(TokenKind::Semicolon, "expected ';' after const");
            }
            $consts[] = new ConstDecl(
                $nameTok->lexeme,
                $value,
                $modifiers->visibility,
                $typeHint,
                $attrs,
                $consts === [] && $first !== null ? $this->endFrom($span, $first) : $this->endFrom($span, $nameTok),
                $modifiers->isFinal,
                $docComment,
            );
            if ($last) { return $consts; }
            $this->advance(); // ','
        }
    }

    /**
     * @param AttributeNode[] $attrs
     */
    private function parseMethod(MemberModifiers $modifiers, array $attrs, Span $span, ?string $docComment = null, ?Token $first = null): MethodDecl
    {
        $this->advance(); // 'function'
        $returnsByRef = $this->match(TokenKind::Ampersand);
        $nameTok = $this->advance();
        if ($nameTok->kind !== TokenKind::Identifier && $nameTok->kind !== TokenKind::Keyword) {
            throw $this->error('expected method name');
        }
        $this->noteDecl($nameTok, $nameTok->lexeme, 'member', 2);
        $this->expect(TokenKind::OpenParen, "expected '(' after method name");
        $params = [];
        if (!$this->check(TokenKind::CloseParen)) {
            $params[] = $this->parseParam();
            while ($this->match(TokenKind::Comma)) {
                if ($this->listEnds(TokenKind::CloseParen)) { break; }
                $params[] = $this->parseParam();
            }
        }
        $this->expect(TokenKind::CloseParen, "expected ')' after parameters");

        $returnType = null;
        if ($this->match(TokenKind::Colon)) {
            $returnType = $this->parseTypeHint();
        }

        $body = null;
        $lazyBody = null;
        if ($this->check(TokenKind::OpenBrace)) {
            if ($this->lazyBodies && !$this->upcomingBlockHasAnonymousClass()) {
                $lazyBody = $this->skipLazyBody();
            } else {
                $body = $this->parseBlock();
            }
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
            $first === null ? $this->end($span) : $this->endFrom($span, $first),
            $returnsByRef,
            $docComment,
            $lazyBody,
        );
    }

    /** @param AttributeNode[] $attrs */
    private function parseFunctionDecl(array $attrs = [], ?Token $first = null): Stmt
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
        $this->noteDecl($nameTok, $this->currentNamespace !== '' ? $this->currentNamespace . '\\' . $nameTok->lexeme : $nameTok->lexeme, 'function', 1);
        $this->expect(TokenKind::OpenParen, "expected '(' after function name");

        $params = [];
        if (!$this->check(TokenKind::CloseParen)) {
            $params[] = $this->parseParam();
            while ($this->match(TokenKind::Comma)) {
                if ($this->listEnds(TokenKind::CloseParen)) {
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

        $body = null;
        $lazyBody = null;
        if ($this->lazyBodies && !$this->upcomingBlockHasAnonymousClass()) {
            $lazyBody = $this->skipLazyBody();
        } else {
            $body = $this->parseBlock();
        }
        // Qualify the function name with the active namespace so it
        // matches the call sites' parseClassName-resolved form.
        $fnName = $nameTok->lexeme;
        if ($this->currentNamespace !== '' && !str_contains($fnName, '\\')) {
            $fnName = $this->currentNamespace . '\\' . $fnName;
        }
        $startSpan = $first === null ? $this->end($startSpan) : $this->endFrom($startSpan, $first);
        $decl = new FunctionDecl(
            $fnName,
            $params,
            $returnType,
            $body,
            $startSpan,
            $returnsByRef,
            $docComment,
            $attrs,
            $lazyBody,
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
        $this->noteVarDecl($varTok, 'param');
        if ($this->collectNames === 1 && ($promoted !== '' || $promotedReadonly)) { $this->noteDecl($varTok, $name, 'member', 2); }

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
            $this->end($startSpan),
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
            if ($this->tolerant === 1 && $this->depth > self::MAX_DEPTH) { throw $this->error('nesting too deep'); }
            $this->depth = $this->depth + ($this->tolerant === 1 ? 1 : 0);
            $inner = $this->parseTypeHint();
            $this->depth = $this->depth - ($this->tolerant === 1 ? 1 : 0);
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

    private function upcomingBlockHasAnonymousClass(): bool
    {
        $depth = 0;
        $previousWasNew = false;
        $n = count($this->tokens);
        for ($i = $this->pos; $i < $n; $i = $i + 1) {
            $tok = $this->tokens[$i];
            if ($previousWasNew && strtolower($tok->lexeme) === 'class') {
                return true;
            }
            if ($tok->kind === TokenKind::OpenBrace) {
                $depth = $depth + 1;
            } elseif ($tok->kind === TokenKind::CloseBrace) {
                $depth = $depth - 1;
                if ($depth === 0) { return false; }
            }
            $previousWasNew = strtolower($tok->lexeme) === 'new';
        }
        return false;
    }

    private function skipLazyBody(): LazyBody
    {
        $open = $this->expect(TokenKind::OpenBrace, "expected '{'");
        $start = $this->offsetForToken($open);
        $depth = 1;
        $end = $start + strlen($open->lexeme);
        while (!$this->isAtEnd() && $depth > 0) {
            $tok = $this->advance();
            if ($tok->kind === TokenKind::OpenBrace) { $depth = $depth + 1; }
            if ($tok->kind === TokenKind::CloseBrace) {
                $depth = $depth - 1;
                if ($depth === 0) {
                    $end = $this->offsetForToken($tok) + strlen($tok->lexeme);
                }
            }
        }
        if ($depth !== 0) { throw $this->error("expected '}'"); }
        return new LazyBody(
            $this->sourceText,
            $start,
            $end,
            $open->line,
            $this->currentNamespace,
            $this->useAliases,
            $this->sourceFile,
            $this->useFnAliases,
        );
    }

    private function offsetForToken(Token $tok): int
    {
        $line = $tok->line - 1;
        $base = $this->lineOffsets[$line] ?? 0;
        return $base + $tok->column - 1;
    }

    private function parseBlock(): Block
    {
        $open = $this->peek();
        $anchor = $this->span();
        if ($this->tolerant === 1 && !$this->check(TokenKind::OpenBrace)) { $this->missingAt($anchor); }   // the Block node stands at the missing brace
        $this->expect(TokenKind::OpenBrace, "expected '{'");
        $stmts = [];
        while (!$this->check(TokenKind::CloseBrace) && !$this->isAtEnd()) {
            if ($this->match(TokenKind::Semicolon)) { continue; }   // empty statement
            $stmts[] = $this->tolerant === 1 ? $this->parseStatementOrRecover() : $this->parseStatement();
        }
        $this->expect(TokenKind::CloseBrace, "expected '}'");
        return new Block($stmts, $this->ranges === 1 ? $this->endFrom($anchor, $open) : null);
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
        $el = $this->prevEndLine;
        $ec = $this->prevEndColumn;
        if ($this->match(TokenKind::Semicolon)) { return new Block([], $this->listSpan(null, $el, $ec)); }   // `for (…);`
        $first = $this->peek();
        if ($this->tolerant === 1 && $this->depth > self::MAX_DEPTH) { throw $this->error('nesting too deep'); }
        $this->depth = $this->depth + ($this->tolerant === 1 ? 1 : 0);
        $stmt = $this->parseStatement();
        $this->depth = $this->depth - ($this->tolerant === 1 ? 1 : 0);
        $el = $this->prevEndLine;
        $ec = $this->prevEndColumn;
        if ($this->tolerant === 1 && $this->ranges === 1) {
            // an Error-only statement consumes nothing: its zero-width point still lies in the body
            $se = $stmt->span;
            if ($se->endLine() > $el || ($se->endLine() === $el && $se->endColumn() > $ec)) { $el = $se->endLine(); $ec = $se->endColumn(); }
        }
        return new Block([$stmt], $this->listSpan($first, $el, $ec));
    }

    /**
     * php's ALTERNATIVE syntax body: a `:` where a `{` would go, statements up
     * to one of `$stops`, and the terminator keyword left UNCONSUMED for the
     * caller (which is what lets `if:` hand off to `elseif` / `else` / `endif`).
     *
     * This is the form every template is written in, because a brace spanning
     * markup is unreadable — so it arrives together with inline HTML rather
     * than as a separate nicety.
     *
     * @param string[] $stops
     */
    private function parseAltBody(array $stops): Block
    {
        $stmts = [];
        $first = null;
        $el = $this->prevEndLine;
        $ec = $this->prevEndColumn;
        while (!$this->isAtEnd()) {
            $stop = false;
            foreach ($stops as $kw) {
                if ($this->checkKeyword($kw)) { $stop = true; break; }
            }
            if ($stop) { break; }
            if ($this->match(TokenKind::Semicolon)) { continue; }   // empty statement
            if ($first === null) { $first = $this->peek(); }
            $stmts[] = $this->tolerant === 1 ? $this->parseStatementOrRecover() : $this->parseStatement();
            $el = $this->prevEndLine;
            $ec = $this->prevEndColumn;
            if ($this->tolerant === 1 && $this->ranges === 1) {
                $se = $stmts[\count($stmts) - 1]->span;
                if ($se->endLine() > $el || ($se->endLine() === $el && $se->endColumn() > $ec)) { $el = $se->endLine(); $ec = $se->endColumn(); }
            }
        }
        return new Block($stmts, $this->listSpan($first, $el, $ec));
    }

    /**
     * Close an alternative-syntax body: the `end…` keyword and its `;`. php
     * also accepts a close tag in place of that `;`, and a template usually
     * writes it that way.
     */
    private function endAltBody(string $keyword): void
    {
        if (!$this->checkKeyword($keyword)) {
            if ($this->tolerant === 1) {
                $this->record($this->error("expected '" . $keyword . "'"));
                return;
            }
            throw $this->error("expected '" . $keyword . "'");
        }
        $this->advance();
        $this->match(TokenKind::Semicolon);
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
        return Stmt::return_($value, $this->end($span));
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
        return Stmt::echo_($exprs, $this->end($span));
    }

    private function parseIf(): Stmt
    {
        $span = $this->span();
        $this->advance(); // 'if'
        $this->expect(TokenKind::OpenParen, "expected '(' after if");
        $cond = $this->parseExpression();
        $this->expect(TokenKind::CloseParen, "expected ')' after condition");

        // The alternative form: arms are separated by keywords rather than
        // nested in braces, so each body runs up to the next arm or to `endif`.
        if ($this->match(TokenKind::Colon)) {
            $then = $this->parseAltBody(['elseif', 'else', 'endif']);
            $elseifs = [];
            $else = null;
            while ($this->checkKeyword('elseif')) {
                $this->advance();
                $this->expect(TokenKind::OpenParen, "expected '(' after elseif");
                $eCond = $this->parseExpression();
                $this->expect(TokenKind::CloseParen, "expected ')' after elseif condition");
                $this->expect(TokenKind::Colon, "expected ':' after elseif condition");
                $elseifs[] = new ElseIfArm($eCond, $this->parseAltBody(['elseif', 'else', 'endif']));
            }
            if ($this->checkKeyword('else')) {
                $this->advance();
                $this->expect(TokenKind::Colon, "expected ':' after else");
                $else = $this->parseAltBody(['endif']);
            }
            $this->endAltBody('endif');
            return Stmt::if_($cond, $then, $elseifs, $else, $this->end($span));
        }

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
                if ($this->tolerant === 1) {
                    if ($this->depth > self::MAX_DEPTH) { throw $this->error('nesting too deep'); }
                    $this->depth = $this->depth + 1;
                }
                $nested = $this->parseIf();
                if ($this->tolerant === 1) { $this->depth = $this->depth - 1; }
                $else = new Block([$nested], $this->wrapStmtSpan($nested));
            } else {
                $else = $this->parseBlockOrStatement();
            }
        }
        return Stmt::if_($cond, $then, $elseifs, $else, $this->end($span));
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
            return Stmt::if_(Expr::bool(true, $this->emptyAt($span)), $block, [], null, $this->end($span));
        }
        if ($this->check(TokenKind::Semicolon)) { $this->advance(); }
        return Stmt::if_(Expr::bool(false, $this->emptyAt($span)), new Block([], $this->ranges === 1 ? $this->emptyAt($span) : null), [], null, $this->end($span));
    }

    private function parseWhile(): Stmt
    {
        $span = $this->span();
        $this->advance(); // 'while'
        $this->expect(TokenKind::OpenParen, "expected '(' after while");
        $cond = $this->parseExpression();
        $this->expect(TokenKind::CloseParen, "expected ')' after while condition");
        if ($this->match(TokenKind::Colon)) {
            $body = $this->parseAltBody(['endwhile']);
            $this->endAltBody('endwhile');
            return Stmt::while_($cond, $body, $this->end($span));
        }
        $body = $this->parseBlockOrStatement();
        return Stmt::while_($cond, $body, $this->end($span));
    }

    private function parseExpressionStatement(?string $docComment = null): Stmt
    {
        $span = $this->span();
        $expr = $this->parseExpression();
        $this->expect(TokenKind::Semicolon, "expected ';' after expression");
        return Stmt::expression($expr, $this->end($span), $docComment);
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
        return Stmt::doWhile($body, $cond, $this->end($span));
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
        if ($this->match(TokenKind::Colon)) {
            $body = $this->parseAltBody(['endfor']);
            $this->endAltBody('endfor');
            return Stmt::for_($init, $cond, $update, $body, $this->end($span));
        }
        $body = $this->parseBlockOrStatement();
        return Stmt::for_($init, $cond, $update, $body, $this->end($span));
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
        if ($this->match(TokenKind::Colon)) {
            $body = $this->parseAltBody(['endforeach']);
            $this->endAltBody('endforeach');
            return Stmt::foreach_($expr, $key, $value, $valueByRef, $body, $this->end($span));
        }
        $body = $this->parseBlockOrStatement();
        return Stmt::foreach_($expr, $key, $value, $valueByRef, $body, $this->end($span));
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
            ? Stmt::break_($level, $this->end($span))
            : Stmt::continue_($level, $this->end($span));
    }

    private function parseThrow(): Stmt
    {
        $span = $this->span();
        $this->advance(); // 'throw'
        $expr = $this->parseExpression();
        $this->expect(TokenKind::Semicolon, "expected ';' after throw");
        return Stmt::throw_($expr, $this->end($span));
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
            $this->noteVarDecl($tok, 'static');
            $default = null;
            if ($this->match(TokenKind::Equals)) {
                $default = $this->parseExpression();
            }
            $decls[] = new StaticLocalDecl($name, $default);
            if (!$this->match(TokenKind::Comma)) { break; }
        }
        $this->expect(TokenKind::Semicolon, "expected ';' after static declaration");
        return Stmt::staticLocal($decls, $this->end($span));
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
        return new \Parser\Ast\GotoStmt($tok->lexeme, $this->end($span));
    }

    /**
     * Top-level `const NAME = <const-expr>;` (outside a class). Lowered to
     * `define("NAME", <expr>)` — the const-registration pre-pass in LowerFromAst
     * picks that up, so a later bareword reference resolves at compile time,
     * matching php's compile-time top-level const. One name per statement.
     */
    /** @param \Parser\Ast\AttributeNode[] $attrs */
    private function parseTopLevelConst(array $attrs = []): Stmt
    {
        $span = $this->span();
        $this->advance(); // 'const'
        $nameTok = $this->expectMemberName('expected const name');
        $this->expect(TokenKind::Equals, "expected '=' in const declaration");
        $value = $this->parseExpression();
        $this->expect(TokenKind::Semicolon, "expected ';' after const declaration");
        $call = new \Parser\Ast\CallExpr(
            'define',
            [new \Parser\Ast\StringLiteral($nameTok->lexeme, $this->tokSpan($span, $nameTok)), $value],
            $this->end($span),
        );
        $stmtSpan = $this->end($span);
        if ($this->ranges === 1 && $attrs !== []) {
            $first = $attrs[0]->span;
            $stmtSpan = new RangedSpan($span->line, $span->column, $span->file, $stmtSpan->endLine(), $stmtSpan->endColumn(), $first->line, $first->column);
        }
        return new \Parser\Ast\ExpressionStmt($call, $stmtSpan, null, $attrs);
    }

    private function parseGlobal(): Stmt
    {
        $span = $this->span();
        $this->advance(); // 'global'
        $names = [];
        while (true) {
            $tok = $this->expect(TokenKind::Variable, "expected variable after 'global'");
            $names[] = ltrim($tok->lexeme, '$');
            $this->noteVarDecl($tok, 'global');
            if (!$this->match(TokenKind::Comma)) { break; }
        }
        $this->expect(TokenKind::Semicolon, "expected ';' after global declaration");
        return Stmt::global_($names, $this->end($span));
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
                $vt = $this->advance();
                $variableName = substr($vt->lexeme, 1);
                $this->noteVarDecl($vt, 'catch');
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
        return Stmt::tryCatch($try, $catches, $finally, $this->end($span));
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
                if ($this->tolerant === 1) {
                    $this->record($this->error("expected 'case' or 'default' in switch body"));
                    $this->skipToSync($this->pos, false);
                    continue;
                }
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
                if ($this->match(TokenKind::Semicolon)) { continue; }   // empty statement
                $body[] = $this->tolerant === 1 ? $this->parseStatementOrRecover() : $this->parseStatement();
            }
            $cases[] = new SwitchArm($value, $body);
        }
        $this->expect(TokenKind::CloseBrace, "expected '}' to close switch");
        return Stmt::switch_($expr, $cases, $this->end($span));
    }

    // ── Expressions (Pratt) ──────────────────────────────────────────────────

    public function parseExpression(): Expr
    {
        return $this->parseWordOr();
    }

    /** Whether the cursor is on the word operator $word (`and` / `or` / `xor`). */
    private function checkWordOp(string $word): bool
    {
        $tok = $this->peek();
        return $tok->kind === TokenKind::Keyword && \strtolower($tok->lexeme) === $word;
    }

    /**
     * `or` / `xor` / `and` — PHP's word operators, and the only three things
     * that bind LOOSER than `=`. That is the whole point of them:
     * `$ok = fopen(…) or die(…)` assigns the fopen result and only then tests
     * it, where `||` would assign the comparison. So they sit above
     * {@see parseAssign} rather than beside `&&` / `||`.
     *
     * Precedence among themselves, loosest first: `or`, `xor`, `and`.
     */
    private function parseWordOr(): Expr
    {
        $left = $this->parseWordXor();
        while ($this->checkWordOp('or')) {
            $span = $this->span();
            $this->advance();
            $right = $this->parseWordXor();
            $left = Expr::binary('||', $left, $right, $this->end($span, $left));
            if ($this->tolerant === 1 && $right->kind === 'Error') { break; }   // no operator chains over missing operands
        }
        return $left;
    }

    private function parseWordXor(): Expr
    {
        $left = $this->parseWordAnd();
        while ($this->checkWordOp('xor')) {
            $span = $this->span();
            $this->advance();
            $right = $this->parseWordAnd();
            // `xor` does NOT short-circuit and has no `^^` counterpart to reuse
            // (`^` is the BITWISE one and would answer an int). Desugared to
            // `!$a !== !$b`, which is exactly logical xor over truthiness and
            // needs no new node kind, no new lowering, and no new inference.
            $left = Expr::binary(
                '!==',
                Expr::unary('!', $left, $this->wrapSpan($span, $left)),
                Expr::unary('!', $right, $this->wrapSpan($span, $right)),
                $this->end($span, $left),
            );
            if ($this->tolerant === 1 && $right->kind === 'Error') { break; }   // no operator chains over missing operands
        }
        return $left;
    }

    private function parseWordAnd(): Expr
    {
        $left = $this->parseAssign();
        while ($this->checkWordOp('and')) {
            $span = $this->span();
            $this->advance();
            $right = $this->parseAssign();
            $left = Expr::binary('&&', $left, $right, $this->end($span, $left));
            if ($this->tolerant === 1 && $right->kind === 'Error') { break; }   // no operator chains over missing operands
        }
        return $left;
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
        if ($this->tolerant !== 1) { return $this->parseAssignInner(); }
        if ($this->depth > self::MAX_DEPTH) { throw $this->error('nesting too deep'); }
        $this->depth = $this->depth + 1;
        $e = $this->parseAssignInner();
        $this->depth = $this->depth - 1;
        return $e;
    }

    private function parseAssignInner(): Expr
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
                return $this->buildAssign('=&', $left, $source, $span);
            }
            $right = $this->parseAssign(); // right-associative
            return $this->buildAssign('=', $left, $right, $span);
        }
        $compoundOp = $this->assignOpFor($tok->kind);
        if ($compoundOp !== null) {
            $span = $this->span();
            $this->advance();
            $right = $this->parseAssign();
            return $this->buildAssign($compoundOp, $left, $right, $span);
        }
        return $left;
    }

    /**
     * Build an assignment, re-associating when the target parsed as a binary
     * expression. PHP binds `=` looser than the comparison / concatenation
     * operators, so `false === $v = foo()` is `false === ($v = foo())` — the
     * assignment attaches to the RIGHTMOST operand (the lvalue) and the operator
     * sees its result, NOT `(false === $v) = foo()`. Recurse into the binary's
     * right operand; a non-binary target assigns directly. `$op` is '=', '=&' or
     * a compound operator like '.='.
     */
    private function buildAssign(string $op, Expr $target, Expr $value, Span $span): Expr
    {
        if ($target->kind === 'BinaryOp') {
            return Expr::binary(
                $target->op,
                $target->left,
                $this->buildAssign($op, $target->right, $value, $span),
                $this->end($target->span, $target->left),
            );
        }
        // A prefix unary binds tighter than `=` too, so `!$x = foo()` is
        // `!($x = foo())` — the assignment attaches to the operand (the lvalue)
        // and the unary sees its result. Same re-association as the binary case
        // (`@$x = …` / `-$x = …` follow identically). `throw` never prefixes an
        // lvalue, so it does not reach here.
        if ($target->kind === 'UnaryOp') {
            return Expr::unary(
                $target->op,
                $this->buildAssign($op, $target->operand, $value, $span),
                $this->end($target->span),
            );
        }
        if ($op === '=')  { return Expr::assign($target, $value, $this->end($span, $target)); }
        if ($op === '=&') { return Expr::refAssign($target, $value, $this->end($span, $target)); }
        return Expr::compoundAssign($op, $target, $value, $this->end($span, $target));
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
            return Expr::yield_(null, $this->parseAssign(), true, $this->end($span));
        }
        if ($this->yieldHasNoOperand()) {
            return Expr::yield_(null, null, false, $this->end($span));
        }
        $value = $this->parseAssign();
        if ($this->peek()->kind === TokenKind::DoubleArrow) {
            $this->advance();
            return Expr::yield_($value, $this->parseAssign(), false, $this->end($span));
        }
        return Expr::yield_(null, $value, false, $this->end($span));
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
                return Expr::ternary($cond, null, $else, $this->end($span, $cond));
            }
            $then = $this->parseExpression();
            $this->expect(TokenKind::Colon, "expected ':' in ternary");
            $else = $this->parseAssign();
            return Expr::ternary($cond, $then, $else, $this->end($span, $cond));
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
            $left = $this->buildPipe($left, $right, $this->end($span, $left));
            if ($this->tolerant === 1 && $right->kind === 'Error') { break; }   // no operator chains over missing operands
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
            // Right-associative, and the right operand is a full ASSIGNMENT
            // expression — php's grammar puts `expr` there, and assignment is an
            // expression. The memoise idiom depends on it:
            //     $d = self::$d ?? self::$d = require '…';
            // parses as `$d = (self::$d ?? (self::$d = require …))`. Recursing
            // into parseNullCoalesce instead stopped below `=`, so the `=` was
            // left for the CALLER and the whole coalesce became an assignment
            // TARGET — "unsupported assign target kind NullCoalesce", which is
            // what blocked tier 1 of the audit ladder.
            //
            // parseAssign, not parseExpression: `and` / `or` / `xor` bind looser
            // than this, so `$a ?? $b and $c` must stay `($a ?? $b) and $c`.
            $right = $this->parseAssign();
            return Expr::nullCoalesce($left, $right, $this->end($span, $left));
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
            $left = Expr::binary('||', $left, $right, $this->end($span, $left));
            if ($this->tolerant === 1 && $right->kind === 'Error') { break; }   // no operator chains over missing operands
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
            $left = Expr::binary('&&', $left, $right, $this->end($span, $left));
            if ($this->tolerant === 1 && $right->kind === 'Error') { break; }   // no operator chains over missing operands
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
            $left = Expr::binary('|', $left, $right, $this->end($span, $left));
            if ($this->tolerant === 1 && $right->kind === 'Error') { break; }   // no operator chains over missing operands
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
            $left = Expr::binary('^', $left, $right, $this->end($span, $left));
            if ($this->tolerant === 1 && $right->kind === 'Error') { break; }   // no operator chains over missing operands
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
            $left = Expr::binary('&', $left, $right, $this->end($span, $left));
            if ($this->tolerant === 1 && $right->kind === 'Error') { break; }   // no operator chains over missing operands
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
            $left = Expr::binary($op, $left, $right, $this->end($span, $left));
            if ($this->tolerant === 1 && $right->kind === 'Error') { break; }   // no operator chains over missing operands
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
            $left = Expr::binary($op, $left, $right, $this->end($span, $left));
            if ($this->tolerant === 1 && $right->kind === 'Error') { break; }   // no operator chains over missing operands
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
            $left = Expr::binary($op, $left, $right, $this->end($span, $left));
            if ($this->tolerant === 1 && $right->kind === 'Error') { break; }   // no operator chains over missing operands
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
            $left = Expr::binary($op, $left, $right, $this->end($span, $left));
            if ($this->tolerant === 1 && $right->kind === 'Error') { break; }   // no operator chains over missing operands
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
            $left = Expr::binary($op, $left, $right, $this->end($span, $left));
            if ($this->tolerant === 1 && $right->kind === 'Error') { break; }   // no operator chains over missing operands
        }
        return $left;
    }

    /**
     * php's precedence, high to low: `**`, then the prefix operators
     * (`- + ~ ++ -- @` and casts), then `instanceof`, then `!`, then `* / %`.
     * So `!$o instanceof A` is `!($o instanceof A)` — it parsed as
     * `(!$o) instanceof A` and answered the opposite — and `-2 ** 2` is -4.
     */
    private function parseInstanceof(): Expr
    {
        if ($this->tolerant !== 1 || !$this->check(TokenKind::Bang)) { return $this->parseInstanceofInner(); }
        if ($this->depth > self::MAX_DEPTH) { throw $this->error('nesting too deep'); }
        $this->depth = $this->depth + 1;
        $e = $this->parseInstanceofInner();
        $this->depth = $this->depth - 1;
        return $e;
    }

    private function parseInstanceofInner(): Expr
    {
        if ($this->check(TokenKind::Bang)) {
            $span = $this->span();
            $this->advance();
            return Expr::unary('!', $this->parseInstanceof(), $this->end($span));
        }
        $left = $this->parseUnary();
        if ($this->checkKeyword('instanceof')) {
            $span = $this->span();
            $this->advance();
            // `$x instanceof $cls` — the RHS names the class through a runtime
            // value. Lower to `is_a($x, $cls)` (identical semantics for an object
            // LHS); the is_a builtin resolves the runtime class name against the
            // module's classes. A written class name keeps the static path.
            // parsePostfix so the RHS may be a full access expression, e.g.
            // `$x instanceof $arg->typeName` (symfony) or `$x instanceof $a[0]`.
            if ($this->check(TokenKind::Variable) || $this->check(TokenKind::OpenParen)) {
                $classExpr = $this->parsePostfix($this->parsePrimary());
                return Expr::call('is_a', [$left, $classExpr], $this->end($span, $left));
            }
            $class = $this->parseClassName();
            return Expr::instanceof_($left, $class, $this->end($span, $left));
        }
        return $left;
    }

    private function parsePower(): Expr
    {
        $left = $this->parsePostfix($this->parsePrimary());
        if ($this->check(TokenKind::StarStar)) {
            $span = $this->span();
            $this->advance();
            // Right-associative, and the exponent may itself be signed: `2 ** -1`.
            $right = $this->parseUnaryNested();
            return Expr::binary('**', $left, $right, $this->end($span, $left));
        }
        return $left;
    }

    private function parseUnary(): Expr
    {
        return $this->parseUnaryInner();
    }

    /** A prefix operator's operand (and `**`'s right side): the self-recursive edge, counted so a chain of operators cannot exhaust the stack. */
    private function parseUnaryNested(): Expr
    {
        if ($this->tolerant !== 1) { return $this->parseUnaryInner(); }
        if ($this->depth > self::MAX_DEPTH) { throw $this->error('nesting too deep'); }
        $this->depth = $this->depth + 1;
        $e = $this->parseUnaryInner();
        $this->depth = $this->depth - 1;
        return $e;
    }

    private function parseUnaryInner(): Expr
    {
        $tok = $this->peek();
        if ($tok->kind === TokenKind::Minus) {
            $span = $this->span();
            $this->advance();
            return Expr::unary('-', $this->parseUnaryNested(), $this->end($span));
        }
        if ($tok->kind === TokenKind::Plus) {
            $span = $this->span();
            $this->advance();
            return Expr::unary('+', $this->parseUnaryNested(), $this->end($span));
        }
        if ($tok->kind === TokenKind::Bang) {
            $span = $this->span();
            $this->advance();
            return Expr::unary('!', $this->parseInstanceof(), $this->end($span));
        }
        if ($tok->kind === TokenKind::Tilde) {
            $span = $this->span();
            $this->advance();
            return Expr::unary('~', $this->parseUnaryNested(), $this->end($span));
        }
        // `@expr` error suppression. Kept as a real node: `trigger_error` now
        // PRINTS, and `@trigger_error(…)` — every symfony deprecation — must
        // stay silent. The lowering consumes the marker (it never reaches MIR),
        // so for everything else `@` is still the transparent pass-through it
        // has always been: nothing else in this runtime emits a diagnostic to
        // suppress.
        if ($tok->kind === TokenKind::AtSign) {
            $span = $this->span();
            $this->advance();
            return Expr::unary('@', $this->parseUnaryNested(), $this->end($span));
        }
        if ($tok->kind === TokenKind::PlusPlus) {
            $span = $this->span();
            $this->advance();
            return Expr::incDec('++', true, $this->parseUnaryNested(), $this->end($span));
        }
        if ($tok->kind === TokenKind::MinusMinus) {
            $span = $this->span();
            $this->advance();
            return Expr::incDec('--', true, $this->parseUnaryNested(), $this->end($span));
        }
        // Cast: `(int)`, `(float)`, `(bool)`, `(string)`, `(array)`, `(object)`.
        if ($tok->kind === TokenKind::OpenParen && $this->looksLikeCast()) {
            $span = $this->span();
            $this->advance(); // '('
            $castTok = $this->advance();
            $this->expect(TokenKind::CloseParen, "expected ')' after cast");
            return Expr::cast(strtolower($castTok->lexeme), $this->parseUnaryNested(), $this->end($span));
        }
        return $this->parsePower();
    }

    private function looksLikeCast(): bool
    {
        // Need at least three tokens: `(`, type-keyword, `)`.
        $next = $this->tokens[$this->pos + 1] ?? null;
        $after = $this->tokens[$this->pos + 2] ?? null;
        if ($next === null || $after === null) { return false; }
        if ($next->kind !== TokenKind::Keyword && !($next->kind === TokenKind::Identifier && strtolower($next->lexeme) === 'object')) { return false; }
        if ($after->kind !== TokenKind::CloseParen) { return false; }
        $kw = strtolower($next->lexeme);
        return $kw === 'int' || $kw === 'integer'
            || $kw === 'float' || $kw === 'double' || $kw === 'real'
            || $kw === 'string'
            || $kw === 'bool'  || $kw === 'boolean'
            // PHP 8.5 `(void) f()` — evaluate and discard, the sanctioned way to
            // silence a #[\NoDiscard].
            || $kw === 'void'
            || $kw === 'array' || $kw === 'object';
    }

    /**
     * Postfix loop — after we have a primary expression, consume any
     * chain of member access, method call, static access, subscript, or
     * postfix inc/dec.
     */
    private function parsePostfix(Expr $left): Expr
    {
        if ($this->tolerant === 1 && $left->kind === 'Error') { return $left; }   // a missing primary takes no suffix chain
        $mark = $this->recorded;
        while (true) {
            if ($this->tolerant === 1 && $this->recorded !== $mark) { return $left; }   // a suffix that reported an error ends the chain
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
                        $nameExpr = Expr::variable(\ltrim($vt->lexeme, '$'), $this->tokSpan($span, $vt));
                    }
                    $left = Expr::dynProp($left, $nameExpr, $nullsafe, $this->end($span, $left));
                    continue;
                }
                if ($this->tolerant === 1) {
                    $nk = $this->peek()->kind;
                    if ($nk !== TokenKind::Identifier && $nk !== TokenKind::Keyword && $nk !== TokenKind::MagicConstant) {
                        $this->record($this->error('expected member name after ->'));
                        $left = Expr::propertyAccess($left, '', $nullsafe, $this->end($span, $left));
                        continue;
                    }
                }
                $nameTok = $this->advance();
                if ($nameTok->kind !== TokenKind::Identifier && $nameTok->kind !== TokenKind::Keyword && $nameTok->kind !== TokenKind::MagicConstant) {
                    throw $this->error('expected member name after ->');
                }
                $name = $nameTok->lexeme;
                if ($this->collectNames === 1) { $this->noteMemberUse($nameTok, $this->check(TokenKind::OpenParen) ? 'method' : 'prop'); }
                if ($this->check(TokenKind::OpenParen)) {
                    $args = $this->parseArgList();
                    $left = Expr::methodCall($left, $name, $args, $nullsafe, $this->end($span, $left));
                } else {
                    $left = Expr::propertyAccess($left, $name, $nullsafe, $this->end($span, $left));
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
                $left = Expr::arrayAccess($left, $index, $this->end($span, $left));
                continue;
            }
            // Indirect call: `$callable(args)` or `($expr)(args)`.
            if ($tok->kind === TokenKind::OpenParen) {
                $span = $this->span();
                $args = $this->parseArgList();
                $left = Expr::invoke($left, $args, $this->end($span, $left));
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
                $this->noteStaticMember($memberTok);
                if ($this->check(TokenKind::OpenParen)) {
                    $args = $this->parseArgList();
                    $left = new \Parser\Ast\DynamicStaticCall($left, $memberName, $args, $this->end($span, $left));
                } else {
                    $left = new \Parser\Ast\DynamicStaticAccess($left, $memberName, $this->end($span, $left));
                }
                continue;
            }
            if ($tok->kind === TokenKind::PlusPlus) {
                $span = $this->span();
                $this->advance();
                $left = Expr::incDec('++', false, $left, $this->end($span, $left));
                continue;
            }
            if ($tok->kind === TokenKind::MinusMinus) {
                $span = $this->span();
                $this->advance();
                $left = Expr::incDec('--', false, $left, $this->end($span, $left));
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
                return [Expr::ellipsis($this->end($span))];
            }
        }
        $args = [];
        if (!$this->check(TokenKind::CloseParen)) {
            $args[] = $this->parseArg();
            while ($this->match(TokenKind::Comma)) {
                if ($this->listEnds(TokenKind::CloseParen)) {
                    break;
                }
                $args[] = $this->parseArg();
            }
        }
        $this->expect(TokenKind::CloseParen, "expected ')' after arguments");
        $spread = false;
        foreach ($args as $a) {
            if ($a->kind === 'Spread') { $spread = true; }
            elseif ($spread && $a->kind === 'NamedArg') { self::$sawNamedSpread = true; }
        }
        return $args;
    }

    /** A call anywhere in the process names an argument after a spread (`f(...$a, name: v)`):
     *  lowering reads it before it has seen every body, to decide how trampolines take their arguments. */
    public static bool $sawNamedSpread = false;

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
            return Expr::spread($value, $this->end($span));
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
                return Expr::namedArg($name, $value, $this->end($span));
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
        $startTok = $this->tokens[$this->pos];
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
        if ($this->collectNames === 1) { $this->setNameRange($startTok, $this->prevEndLine, $this->prevEndColumn); }
        return implode('\\', $parts);
    }

    private function setNameRange(Token $start, int $endLine, int $endColumn): void
    {
        $this->nmLine = $start->line;
        $this->nmColumn = $start->column;
        $this->nmEndLine = $endLine;
        $this->nmEndColumn = $endColumn;
    }

    /** Record the name parseClassNameLiteral() read last (call before anything else is consumed). */
    private function noteName(string $written, string $resolved, string $kind, bool $globalFallback, string $role = ''): void
    {
        $this->names[(string)$this->nmLine . ':' . (string)$this->nmColumn . ':' . $kind . $role] = new NameRef(
            new RangedSpan($this->nmLine, $this->nmColumn, $this->sourceFile, $this->nmEndLine, $this->nmEndColumn),
            $written,
            $resolved,
            $kind,
            $globalFallback,
            $role,
        );
    }

    /** A declaration name token: level 1 = class-likes, functions, enum cases (a member kind); level 2 adds methods, properties and class constants. */
    private function noteDecl(Token $tok, string $resolved, string $kind, int $level): void
    {
        if ($this->collectNames !== 1 || $this->declNames < $level) { return; }
        $this->setNameRange($tok, $tok->line, $tok->column + \strlen($tok->lexeme));
        $this->noteName($tok->lexeme, $resolved, $kind, false, 'decl');
    }

    /** A member-name USE (level 2): role prop | method | const | sprop | case; `resolved` is the bare name, `written` the lexeme (`$p` for sprop). */
    private function noteMemberUse(Token $tok, string $role): void
    {
        if ($this->collectNames !== 1 || $this->declNames < 2) { return; }
        $this->setNameRange($tok, $tok->line, $tok->column + \strlen($tok->lexeme));
        $this->noteName($tok->lexeme, \ltrim($tok->lexeme, '$'), 'member', false, $role);
    }

    /** The member token after `::` (call before `(` is consumed). Enum cases are class constants syntactically: they read as `const`. `::class` is no member. */
    private function noteStaticMember(Token $tok): void
    {
        if ($this->collectNames !== 1 || $this->declNames < 2) { return; }
        if ($tok->kind === TokenKind::Variable) {
            if ($tok->lexeme !== '$' && !$this->check(TokenKind::OpenParen)) { $this->noteMemberUse($tok, 'sprop'); }
            return;
        }
        if ($tok->kind !== TokenKind::Identifier && $tok->kind !== TokenKind::Keyword && $tok->kind !== TokenKind::MagicConstant) { return; }
        if ($this->check(TokenKind::OpenParen)) { $this->noteMemberUse($tok, 'method'); return; }
        if (\strtolower($tok->lexeme) === 'class') { return; }
        $this->noteMemberUse($tok, 'const');
    }

    /** A variable DECLARATION (level 2): role param | catch | global | static; `written` is the lexeme with its `$`, `resolved` without. */
    private function noteVarDecl(Token $tok, string $role): void
    {
        if ($this->collectNames !== 1 || $this->declNames < 2 || $tok->lexeme === '') { return; }
        $this->setNameRange($tok, $tok->line, $tok->column + \strlen($tok->lexeme));
        $this->noteName($tok->lexeme, \ltrim($tok->lexeme, '$'), 'variable', false, $role);
    }

    private function useRole(string $kind): string
    {
        if ($this->declNames === 0) { return ''; }
        return $kind === 'const' ? 'constant' : $kind;
    }

    /** A class reference: scalar / pseudo type names are not names. */
    private function noteClass(string $literal, string $resolved): void
    {
        $low = strtolower($literal);
        if ($low === 'int' || $low === 'integer' || $low === 'bool' || $low === 'boolean'
            || $low === 'float' || $low === 'double' || $low === 'string' || $low === 'array'
            || $low === 'mixed' || $low === 'void' || $low === 'null' || $low === 'callable'
            || $low === 'iterable' || $low === 'object' || $low === 'never' || $low === 'false'
            || $low === 'true' || $low === 'numeric'
        ) {
            return;
        }
        $this->noteName($literal, $resolved, 'class', false);
    }

    /** What a name read by parseNameOrCall() is, from the token after it. */
    private function noteNameOrCall(string $literal, string $resolved, Token $next): void
    {
        if ($next->kind === TokenKind::DoubleColon) {
            $this->noteName($literal, $resolved, 'class', false);
            return;
        }
        // An unqualified function/constant name never goes through the class `use` aliases.
        $unqualified = !str_contains($literal, '\\');
        $ns = $this->currentNamespace;
        $local = $ns !== '' ? $ns . '\\' . $literal : $literal;
        if ($next->kind === TokenKind::OpenParen) {
            $fl = strtolower($literal);
            if (!$unqualified) {
                $this->noteName($literal, $resolved, 'function', false);
            } elseif (isset($this->useFnAliases[$fl])) {
                $this->noteName($literal, $this->useFnAliases[$fl], 'function', false);
            } else {
                $this->noteName($literal, $local, 'function', $ns !== '');
            }
            return;
        }
        if (!$unqualified) {
            $this->noteName($literal, $resolved, 'constant', false);
        } elseif (isset($this->useConstAliases[$literal])) {
            $this->noteName($literal, $this->useConstAliases[$literal], 'constant', false);
        } else {
            $this->noteName($literal, $local, 'constant', $ns !== '');
        }
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
        $resolved = $this->resolveClassName($literal);
        if ($this->collectNames === 1) { $this->noteClass($literal, $resolved); }
        return $resolved;
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
        if (str_starts_with($low, 'namespace\\')) {
            $rest = substr($literal, 10);
            return $this->currentNamespace !== '' ? $this->currentNamespace . '\\' . $rest : $rest;
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

        // `#[Attr] fn () => …` / `#[Attr] static function () {}`: a closure's attributes
        // are metadata no running code reads, so they are parsed and dropped.
        if ($tok->kind === TokenKind::AttributeStart) {
            $this->collectAttributes();
            return $this->parsePrimary();
        }

        if ($tok->kind === TokenKind::IntLiteral) {
            $this->advance();
            return Expr::int($this->parseIntLiteral($tok->lexeme), $this->end($span));
        }
        if ($tok->kind === TokenKind::FloatLiteral) {
            $this->advance();
            return Expr::float((float)str_replace('_', '', $tok->lexeme), $this->end($span));
        }
        if ($tok->kind === TokenKind::StringLiteral) {
            $this->advance();
            if ($this->needsInterp($tok->lexeme)) {
                return $this->parseInterpolated($tok->lexeme, $this->end($span), $tok);
            }
            return Expr::string($this->unquoteString($tok->lexeme), $this->end($span));
        }
        if ($tok->kind === TokenKind::Variable) {
            $this->advance();
            return Expr::variable(substr($tok->lexeme, 1), $this->end($span));
        }
        if ($tok->kind === TokenKind::MagicConstant) {
            $this->advance();
            // __FILE__ / __DIR__ fold to string literals right here: they are the two
            // magic constants whose value is the FILE, and lowering only ever sees a
            // flattened statement list with no per-file identity left.
            $mn = \strtoupper($tok->lexeme);
            if ($mn === '__FILE__') { return Expr::string($this->sourceFile, $this->end($span)); }
            if ($mn === '__DIR__')  { return Expr::string($this->sourceDir(), $this->end($span)); }
            // …and __NAMESPACE__, whose value is the PARSE context: lowering has
            // no namespace left, so it answered '' — symfony-style discovery
            // (`sprintf('%s\%s', __NAMESPACE__, $base)` then `new $class()`)
            // built a global name and instantiated nothing.
            if ($mn === '__NAMESPACE__') { return Expr::string($this->currentNamespace, $this->end($span)); }
            return Expr::magicConstant($tok->lexeme, $this->end($span));
        }
        if ($tok->kind === TokenKind::Keyword) {
            $lower = strtolower($tok->lexeme);
            if ($lower === 'true')  { $this->advance(); return Expr::bool(true,  $this->end($span)); }
            if ($lower === 'false') { $this->advance(); return Expr::bool(false, $this->end($span)); }
            if ($lower === 'null')  { $this->advance(); return Expr::null($this->end($span)); }
            if ($lower === 'new')   { return $this->parseNew($span); }
            if ($lower === 'clone') {
                $this->advance();
                // PHP 8.5 clone-with: `clone($obj, ['p' => v])` — call shape.
                // `clone($obj)` is just a parenthesized operand (no props).
                if ($this->check(TokenKind::OpenParen)) {
                    $cargs = $this->parseArgList();
                    $cobj = $cargs[0];
                    $cwith = \count($cargs) > 1 ? $cargs[1] : null;
                    return Expr::clone_($cobj, $cwith, $this->end($span));
                }
                return Expr::clone_($this->parseUnaryNested(), null, $this->end($span));
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
                return Expr::call($lower, $args, $this->end($span));
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
            // src scan pull every autoload file, including the one being
            // required — so the LOAD is redundant. Its VALUE is not: a file
            // ending in `return [...]` (symfony's config/bundles.php, every
            // dumped-container data file) or `return function (…) {…}` is the
            // whole point of the expression, and this used to yield null.
            //
            // The path operand is now EVALUATED (it can have side effects, and
            // php evaluates it) and handed to `__mc_require_value`, which maps a
            // path to the slot holding that file's top-level return value
            // ({@see \Manticore\__mc_include_slot}).
            if ($lower === 'require' || $lower === 'require_once'
                    || $lower === 'include' || $lower === 'include_once') {
                $this->advance();
                return Expr::call('__mc_require_value', [$this->parseExpression()], $this->end($span));
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
                return Expr::unary('throw', $inner, $this->end($span));
            }
            // `print` is an EXPRESSION, not a statement: it prints one operand
            // and yields int 1, so `$ok = print "x";` and `$c and print "y";`
            // are both legal. Lowest precedence and right-associative, which
            // falls out of consuming the operand with parseExpression — the
            // same shape `throw` above uses. The statement form `print "x";`
            // needs nothing extra: parseStatement has no `print` arm, so it
            // already falls through to the expression statement.
            if ($lower === 'print') {
                $this->advance();
                // parseAssign, NOT parseExpression: `print` binds TIGHTER than
                // the word operators, so `print $a and $b` is `(print $a) and $b`.
                // Taking a full expression here would swallow the `and`.
                $inner = $this->parseAssign();
                return Expr::unary('print', $inner, $this->end($span));
            }
            if ($lower === 'namespace' && $this->namespaceRelativeAhead()) {
                return $this->parseNameOrCall($span);
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

        if ($this->tolerant === 1) {
            $this->record($this->error('unexpected token in expression: ' . $tok->kind));
            return new \Parser\Ast\ErrorExpr($this->missingAt($span));
        }
        throw $this->error('unexpected token in expression: ' . $tok->kind);
    }

    private function parseArrowFn(bool $isStatic, Span $span): Expr
    {
        $this->advance(); // 'fn'
        // `fn &() => …` returns by reference, exactly as `function &f()` does —
        // the machinery already exists for named functions and methods, only
        // the closure forms never parsed the `&`. symfony/dependency-injection
        // uses it twice to alias a private property out of a bound closure:
        // `$x = &\Closure::bind(fn &() => $this->instanceof, $o, $o)();`
        $returnsByRef = $this->match(TokenKind::Ampersand);
        $params = $this->parseParenParamList();
        $returnType = null;
        if ($this->match(TokenKind::Colon)) {
            $returnType = $this->parseTypeHint();
        }
        $this->expect(TokenKind::DoubleArrow, "expected '=>' in arrow function");
        $body = $this->parseExpression();
        return Expr::arrowFn($isStatic, $params, $returnType, $body, $this->end($span), $returnsByRef);
    }

    private function parseClosure(bool $isStatic, Span $span): Expr
    {
        $this->advance(); // 'function'
        // `function &() {…}` — same by-reference return as the named form.
        $returnsByRef = $this->match(TokenKind::Ampersand);
        $params = $this->parseParenParamList();
        $uses = [];
        if ($this->checkKeyword('use')) {
            $this->advance();
            $this->expect(TokenKind::OpenParen, "expected '(' after use");
            if (!$this->check(TokenKind::CloseParen)) {
                $uses[] = $this->parseClosureUse();
                while ($this->match(TokenKind::Comma)) {
                    if ($this->listEnds(TokenKind::CloseParen)) { break; }
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
        return Expr::closure($isStatic, $params, $uses, $returnType, $body, $this->end($span), $returnsByRef);
    }

    private function parseClosureUse(): ClosureUse
    {
        $first = $this->peek();
        $byRef = $this->match(TokenKind::Ampersand);
        $varTok = $this->expect(TokenKind::Variable, 'expected $variable in use list');
        return new ClosureUse(substr($varTok->lexeme, 1), $byRef, $this->listSpan($first, $this->prevEndLine, $this->prevEndColumn));
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
                if ($this->listEnds(TokenKind::CloseParen)) { break; }
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
            // a missing arrow cannot recover in place: the arm's body would be read from the wrong token
            if ($this->tolerant === 1 && !$this->check(TokenKind::DoubleArrow)) { throw $this->error("expected '=>' in match arm"); }
            $this->expect(TokenKind::DoubleArrow, "expected '=>' in match arm");
            $body = $this->parseExpression();
            $arms[] = new MatchArm($conds, $body);
            if (!$this->match(TokenKind::Comma)) {
                break;
            }
        }
        $this->expect(TokenKind::CloseBrace, "expected '}' to close match");
        return Expr::match_($subject, $arms, $this->end($span));
    }

    private function parseNew(Span $span): Expr
    {
        $this->advance(); // 'new'
        // Anonymous class: `new class(args) extends X implements Y { … }`.
        if ($this->checkKeyword('class')) {
            return $this->parseAnonClass($span);
        }
        // PHP also permits a parenthesized class expression:
        // `new ($expr)(args)`. Reuse NewDynExpr so lowering and emission keep
        // the existing runtime class-id dispatch and ownership behavior.
        if ($this->match(TokenKind::OpenParen)) {
            $classExpr = $this->parseExpression();
            $this->expect(TokenKind::CloseParen, "expected ')' after dynamic class expression");
            $dargs = [];
            if ($this->check(TokenKind::OpenParen)) {
                $dargs = $this->parseArgList();
            }
            return new \Parser\Ast\NewDynExpr($classExpr, $dargs, $this->end($span));
        }
        // `new $cls(args)` — the class is named by a value, not written in the
        // source. Only the variable form: `new ($expr)(args)` would make the
        // first paren ambiguous with the argument list.
        if ($this->check(TokenKind::Variable)) {
            $classExpr = $this->parsePrimary();
            // PHP's `new_variable`: property fetches and subscripts name the class
            // (`new $a->b[0]()`), but a call ends it — `(` starts the constructor's
            // argument list, so `new $a->b()` is `new ($a->b)()`, not `(new $a)->b()`.
            while (true) {
                $tok = $this->peek();
                if ($tok->kind === TokenKind::Arrow || $tok->kind === TokenKind::NullsafeArrow) {
                    $nullsafe = $tok->kind === TokenKind::NullsafeArrow;
                    $mspan = $this->span();
                    $this->advance();
                    if ($this->check(TokenKind::Variable) || $this->check(TokenKind::OpenBrace)) {
                        if ($this->check(TokenKind::OpenBrace)) {
                            $this->advance();
                            $nameExpr = $this->parseExpression();
                            $this->expect(TokenKind::CloseBrace, "expected '}' after dynamic member");
                        } else {
                            $vt = $this->advance();
                            $nameExpr = Expr::variable(\ltrim($vt->lexeme, '$'), $this->tokSpan($mspan, $vt));
                        }
                        $classExpr = Expr::dynProp($classExpr, $nameExpr, $nullsafe, $this->end($mspan, $classExpr));
                        continue;
                    }
                    $nameTok = $this->advance();
                    if ($nameTok->kind !== TokenKind::Identifier && $nameTok->kind !== TokenKind::Keyword && $nameTok->kind !== TokenKind::MagicConstant) {
                        throw $this->error('expected member name after ->');
                    }
                    $this->noteMemberUse($nameTok, 'prop');
                    $classExpr = Expr::propertyAccess($classExpr, $nameTok->lexeme, $nullsafe, $this->end($mspan, $classExpr));
                    continue;
                }
                if ($tok->kind === TokenKind::OpenBracket) {
                    $mspan = $this->span();
                    $this->advance();
                    $index = $this->parseExpression();
                    $this->expect(TokenKind::CloseBracket, "expected ']' after subscript");
                    $classExpr = Expr::arrayAccess($classExpr, $index, $this->end($mspan, $classExpr));
                    continue;
                }
                break;
            }
            $dargs = [];
            if ($this->check(TokenKind::OpenParen)) {
                $dargs = $this->parseArgList();
            }
            return new \Parser\Ast\NewDynExpr($classExpr, $dargs, $this->end($span));
        }
        // `new static::$cls(args)` / `new Foo::$cls(args)` — the class is the VALUE
        // of a static property (PHP's new_variable). Left to parseClassName it
        // read `new static` and then a static call through a local `$cls`.
        $save = $this->pos;
        $literal = $this->parseClassNameLiteral();
        $dc = $this->peek();
        $pv = $this->tokens[$this->pos + 1] ?? null;
        if ($dc->kind === TokenKind::DoubleColon && $pv !== null && $pv->kind === TokenKind::Variable && $pv->lexeme !== '$') {
            if ($this->collectNames === 1) { $this->noteClass($literal, $this->resolveClassName($literal)); }
            $this->advance();
            $this->noteMemberUse($this->advance(), 'sprop');
            $classExpr = Expr::staticAccess($this->resolveClassName($literal), $pv->lexeme, $this->endFrom($span, $this->tokens[$save]));
            $dargs = [];
            if ($this->check(TokenKind::OpenParen)) {
                $dargs = $this->parseArgList();
            }
            return new \Parser\Ast\NewDynExpr($classExpr, $dargs, $this->end($span));
        }
        $this->rewind($save);
        $class = $this->parseClassName();
        $args = [];
        if ($this->check(TokenKind::OpenParen)) {
            $args = $this->parseArgList();
        }
        return Expr::new_($class, $args, $this->end($span));
    }

    /**
     * `new class(ctorArgs) extends X implements Y { … }` — parse the inline
     * declaration under a synthetic unique name, hoist it to the program's
     * top-level class list (so it's registered + lowered like any class), and
     * yield `new <synthName>(ctorArgs)`.
     */
    private function parseAnonClass(Span $span): Expr
    {
        $classTok = $this->advance(); // 'class'
        $args = [];
        if ($this->check(TokenKind::OpenParen)) {
            $args = $this->parseArgList();
        }
        // A valid identifier (LLVM symbol-safe) — PHP's `class@anonymous` form
        // carries an `@` that the mangler can't emit.
        if ($this->tolerant === 1) {
            $name = '__anon_class_' . (string)\crc32($this->sourceFile === '' ? '0' : $this->sourceFile)
                . '_' . (string)$classTok->line . '_' . (string)$classTok->column;
        } else {
            $name = '__anon_class_' . (string)$this->anonClassCounter;
            $this->anonClassCounter = $this->anonClassCounter + 1;
        }
        if ($this->currentNamespace !== '') {
            $name = $this->currentNamespace . '\\' . $name;
        }
        $decl = $this->finishClassDecl('class', $name, [], false, false, false, $span);
        $this->hoistedClasses[] = $decl;
        return Expr::new_($name, $args, $this->end($span));
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
                if ($this->listEnds($closeKind)) {
                    break;
                }
                $elements[] = $this->parseArrayElementOrHole($span);
            }
        }
        $this->expect($closeKind, $closeMsg);
        return Expr::arrayLit($elements, $this->end($span));
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
            $at = $this->peek();
            return new ArrayElement(null, Expr::variable('__mc_destructure_skip', $this->emptyAtTok($span, $at)));
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
            return new ArrayElement(null, Expr::spread($value, $this->end($span)));
        }
        // `[&$a[$k], $v]` and `['k' => &$v]`. Accepting the `&` here replaces a
        // bare "unexpected token: Ampersand" — which points at the character and
        // explains nothing — with a diagnostic from lowering that names the
        // construct and says why it cannot be honoured. The flag is carried, not
        // obeyed: see LowerFromAst::lowerArrayLit.
        if ($this->match(TokenKind::Ampersand)) {
            return new ArrayElement(null, $this->parseExpression(), true);
        }
        $first = $this->parseExpression();
        if ($this->check(TokenKind::DoubleArrow)) {
            $this->advance();
            if ($this->match(TokenKind::Ampersand)) {
                return new ArrayElement($first, $this->parseExpression(), true);
            }
            $value = $this->parseExpression();
            return new ArrayElement($first, $value);
        }
        return new ArrayElement(null, $first);
    }

    /**
     * Identifier-led primary: function call, static-class reference, or bare name.
     */
    /** `namespace\name`: the keyword at the cursor starts a namespace-relative name, not a declaration. */
    private function namespaceRelativeAhead(): bool
    {
        $next = $this->tokens[$this->pos + 1] ?? null;
        return $next !== null && $next->kind === TokenKind::Backslash;
    }

    private function parseNameOrCall(Span $span): Expr
    {
        $literal = $this->parseClassNameLiteral();
        $name = $this->resolveClassName($literal);
        $tok = $this->peek();
        if ($this->collectNames === 1) { $this->noteNameOrCall($literal, $name, $tok); }

        if ($tok->kind === TokenKind::DoubleColon) {
            $this->advance();
            $member = $this->advance();
            // `Class::${expr}` / `Class::$$var` — the static property NAME is
            // computed. The lexer scans `$` on its own when no identifier
            // follows, so a bare `$` lexeme here is the marker; `{ … }` wraps an
            // arbitrary expression (symfony/error-handler DebugClassLoader
            // writes `self::${$annotation.'Methods'}`) and a following Variable
            // is the `$$name` spelling.
            if ($member->kind === TokenKind::Variable && $member->lexeme === '$') {
                if ($this->match(TokenKind::OpenBrace)) {
                    $nameExpr = $this->parseExpression();
                    $this->expect(TokenKind::CloseBrace, "expected '}' after a dynamic static property name");
                    return new \Parser\Ast\DynamicStaticProp($name, $nameExpr, $this->end($span));
                }
                if ($this->check(TokenKind::Variable)) {
                    $inner = $this->advance();
                    $nameExpr = Expr::variable(\substr($inner->lexeme, 1), $this->tokSpan($span, $inner));
                    return new \Parser\Ast\DynamicStaticProp($name, $nameExpr, $this->end($span));
                }
            }
            // `Class::{expr}(...)` / `Class::$name(...)` — the METHOD name is
            // computed (php-cs-fixer: `self::{$this->fixingMap[$k]}(...)`).
            if ($member->kind === TokenKind::OpenBrace) {
                $nameExpr = $this->parseExpression();
                $this->expect(TokenKind::CloseBrace, "expected '}' after a dynamic static method name");
                if (!$this->check(TokenKind::OpenParen)) {
                    throw $this->error("expected '(' after a dynamic static method name");
                }
                return new \Parser\Ast\DynamicStaticMethodCall($name, $nameExpr, $this->parseArgList(), $this->end($span));
            }
            if ($member->kind === TokenKind::Variable && $this->check(TokenKind::OpenParen)) {
                $nameExpr = Expr::variable(\substr($member->lexeme, 1), $this->tokSpan($span, $member));
                return new \Parser\Ast\DynamicStaticMethodCall($name, $nameExpr, $this->parseArgList(), $this->end($span));
            }
            // `Class::method(...)`
            $this->noteStaticMember($member);
            if ($this->check(TokenKind::OpenParen)) {
                $args = $this->parseArgList();
                return Expr::staticCall($name, $member->lexeme, $args, $this->end($span));
            }
            // `Class::$prop` or `Class::CONST` or `Class::class`
            if ($member->kind === TokenKind::Variable) {
                return Expr::staticAccess($name, $member->lexeme, $this->end($span));
            }
            return Expr::staticAccess($name, $member->lexeme, $this->end($span));
        }

        // `name(args)` — only for unqualified names; qualified function calls
        // are rare but accepted too.
        if ($this->check(TokenKind::OpenParen)) {
            $args = $this->parseArgList();
            $fl = \strtolower($literal);
            $qualified = \str_contains($literal, '\\');
            if (isset($this->useFnAliases[$fl]) && !$qualified) { $name = $this->useFnAliases[$fl]; }
            return Expr::call($name, $args, $this->end($span), $qualified);
        }

        return Expr::identifier($name, $this->end($span));
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

    /** Interpolation cursor: byte offset into the raw string lexeme and the file line/column there (tolerant + ranges). */
    private int $ipOff = 0;
    private int $ipLine = 0;
    private int $ipCol = 0;

    /** One code point as UTF-8 bytes, for the `\u{…}` escape. */
    private function utf8Encode(int $cp): string
    {
        if ($cp < 0x80) { return \chr($cp); }
        if ($cp < 0x800) {
            return \chr(0xC0 | ($cp >> 6)) . \chr(0x80 | ($cp & 0x3F));
        }
        if ($cp < 0x10000) {
            return \chr(0xE0 | ($cp >> 12)) . \chr(0x80 | (($cp >> 6) & 0x3F))
                . \chr(0x80 | ($cp & 0x3F));
        }
        return \chr(0xF0 | ($cp >> 18)) . \chr(0x80 | (($cp >> 12) & 0x3F))
            . \chr(0x80 | (($cp >> 6) & 0x3F)) . \chr(0x80 | ($cp & 0x3F));
    }

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
        if ($c === 'u' && \substr($body, $i + 1, 1) === '{') {
            // `\u{HHH}` (PHP 7.0) — a UTF-8 code point, not a byte. An
            // unterminated or empty brace group is NOT an escape in php: the
            // whole `\u` stays literal.
            $val = 0; $got = 0; $j = $i + 2;
            while ($j < $n) {
                $hv = $this->hexDigitVal(\substr($body, $j, 1));
                if ($hv < 0) { break; }
                $val = $val * 16 + $hv; $got = $got + 1; $j = $j + 1;
            }
            if ($got === 0 || \substr($body, $j, 1) !== '}') { return '\\u'; }
            $this->escapeLen = ($j - $i) + 1;
            return $this->utf8Encode($val);
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
     *
     * Tolerant + ranges: every part gets its own range, computed by walking the
     * RAW lexeme from the string token's start (columns are bytes, a newline
     * resets the column, exactly like the lexer), and the `{$expr}` body is
     * lexed at its real position so its nodes and written names are real too.
     * A heredoc lexeme is normalised (opener and indentation gone), so its walk
     * does not end where the token does: those strings keep the whole-string
     * range on every part. `${x}` stays literal text, as in the strict parser.
     */
    private function parseInterpolated(string $lex, Span $span, ?Token $tok = null): Expr
    {
        $exact = false;
        if ($tok !== null && $this->tolerant === 1 && $this->ranges === 1) {
            $this->ipOff = 0;
            $this->ipLine = $tok->line;
            $this->ipCol = $tok->column;
            $this->interpAt($lex, \strlen($lex));
            $exact = $this->ipLine === $tok->endLine() && $this->ipCol === $tok->endColumn();
            $this->ipOff = 0;
            $this->ipLine = $tok->line;
            $this->ipCol = $tok->column;
        }
        $body = \substr($lex, 1, \strlen($lex) - 2);
        $n = \strlen($body);
        $parts = [];
        /** @var Expr[] $inners */
        $inners = [];
        /** @var int[] $endLines */
        $endLines = [];
        /** @var int[] $endCols */
        $endCols = [];
        /** @var string[] literal text of a literal part, '' for any other */
        $litVals = [];
        $lit = '';
        $litStart = 0;
        $i = 0;
        while ($i < $n) {
            $c = \substr($body, $i, 1);
            if ($c === '\\' && $i + 1 < $n) {
                if ($lit === '') { $litStart = $i; }
                $lit .= $this->decodeEscapeSeq($body, $n, $i + 1);
                $i = $i + 1 + $this->escapeLen;
                continue;
            }
            // Complex: {$expr}
            if ($c === '{' && $i + 1 < $n && \substr($body, $i + 1, 1) === '$') {
                if ($lit !== '') { $parts[] = $this->interpLit($lex, $lit, $litStart, $i, $span, $exact); $inners[] = $parts[\count($parts) - 1]; $litVals[] = $lit; $endLines[] = $this->ipLine; $endCols[] = $this->ipCol; $lit = ''; }
                $depth = 1;
                $j = $i + 1;
                while ($j < $n) {
                    $cj = \substr($body, $j, 1);
                    if ($cj === '{') { $depth = $depth + 1; }
                    if ($cj === '}') { $depth = $depth - 1; if ($depth === 0) { break; } }
                    $j = $j + 1;
                }
                $inner = \substr($body, $i + 1, $j - $i - 1);
                if ($exact) {
                    $this->interpAt($lex, $i + 1);
                    $sl = $this->ipLine;
                    $sc = $this->ipCol;
                    $this->interpAt($lex, $i + 2);
                    $part = $this->parseSubExpr($inner, $span, $this->ipLine, $this->ipCol);
                    if ($j >= $n) {
                        $this->interpAt($lex, $j + 1);
                        $this->record(new ParseError('Unterminated "{$" interpolation', $sl, $sc, $this->ipLine, $this->ipCol));
                    } else {
                        $this->interpAt($lex, $j + 2);
                    }
                } else {
                    $part = $this->parseSubExpr($inner, $span);
                }
                $parts[] = Expr::cast('string', $part, $exact ? $part->span : $span);
                $inners[] = $part;
                $litVals[] = '';
                $endLines[] = $this->ipLine;
                $endCols[] = $this->ipCol;
                $i = $j + 1;
                continue;
            }
            // Simple: $name [-> prop | [idx]]
            if ($c === '$' && $i + 1 < $n && $this->isIdentStart(\substr($body, $i + 1, 1))) {
                if ($lit !== '') { $parts[] = $this->interpLit($lex, $lit, $litStart, $i, $span, $exact); $inners[] = $parts[\count($parts) - 1]; $litVals[] = $lit; $endLines[] = $this->ipLine; $endCols[] = $this->ipCol; $lit = ''; }
                $a = $i;
                $i = $i + 1;
                $ns = $i;
                while ($i < $n && $this->isIdentChar(\substr($body, $i, 1))) { $i = $i + 1; }
                $vSpan = $span;
                $sl = 0;
                $sc = 0;
                if ($exact) {
                    $this->interpAt($lex, $a + 1);
                    $sl = $this->ipLine;
                    $sc = $this->ipCol;
                    $this->interpAt($lex, $i + 1);
                    $vSpan = $this->interpRange($span, $sl, $sc);
                }
                $expr = Expr::variable(\substr($body, $ns, $i - $ns), $vSpan);
                if ($i + 2 < $n && \substr($body, $i, 1) === '-' && \substr($body, $i + 1, 1) === '>'
                    && $this->isIdentStart(\substr($body, $i + 2, 1))) {
                    $i = $i + 2;
                    $ps = $i;
                    while ($i < $n && $this->isIdentChar(\substr($body, $i, 1))) { $i = $i + 1; }
                    $aSpan = $span;
                    if ($exact) {
                        $this->interpAt($lex, $i + 1);
                        $aSpan = $this->interpRange($span, $sl, $sc);
                        if ($this->collectNames === 1 && $this->declNames >= 2) {
                            $pn = \substr($body, $ps, $i - $ps);
                            $this->nmLine = $this->ipLine;
                            $this->nmColumn = $this->ipCol - \strlen($pn);
                            $this->nmEndLine = $this->ipLine;
                            $this->nmEndColumn = $this->ipCol;
                            $this->noteName($pn, $pn, 'member', false, 'prop');
                        }
                    }
                    $expr = Expr::propertyAccess($expr, \substr($body, $ps, $i - $ps), false, $aSpan);
                } elseif ($i < $n && \substr($body, $i, 1) === '[') {
                    $i = $i + 1;
                    $ks = $i;
                    while ($i < $n && \substr($body, $i, 1) !== ']') { $i = $i + 1; }
                    $key = \substr($body, $ks, $i - $ks);
                    $kSpan = $span;
                    if ($exact) {
                        $this->interpAt($lex, $ks + 1);
                        $kl = $this->ipLine;
                        $kc = $this->ipCol;
                        $this->interpAt($lex, $i + 1);
                        $kSpan = $this->interpRange($span, $kl, $kc);
                    }
                    if ($i < $n) { $i = $i + 1; }
                    $aSpan = $span;
                    if ($exact) {
                        $this->interpAt($lex, $i + 1);
                        $aSpan = $this->interpRange($span, $sl, $sc);
                    }
                    $expr = Expr::arrayAccess($expr, $this->interpArrayKey($key, $kSpan), $aSpan);
                }
                $parts[] = Expr::cast('string', $expr, $exact ? $expr->span : $span);
                $inners[] = $expr;
                $litVals[] = '';
                $endLines[] = $this->ipLine;
                $endCols[] = $this->ipCol;
                continue;
            }
            if ($lit === '') { $litStart = $i; }
            $lit .= $c;
            $i = $i + 1;
        }
        if ($lit !== '') { $parts[] = $this->interpLit($lex, $lit, $litStart, $n, $span, $exact); $inners[] = $parts[\count($parts) - 1]; $litVals[] = $lit; $endLines[] = $this->ipLine; $endCols[] = $this->ipCol; }
        if (\count($parts) === 0) { return Expr::string('', $span); }
        if ($exact && \count($parts) === 1) {
            return $litVals[0] !== '' ? Expr::string($litVals[0], $span) : Expr::cast('string', $inners[0], $span);
        }
        $result = $parts[0];
        $k = 1;
        while ($k < \count($parts)) {
            $bSpan = $span;
            if ($exact && $k + 1 < \count($parts)) {
                $bSpan = new RangedSpan($span->line, $span->column, $span->file, $endLines[$k], $endCols[$k], $span->startLine(), $span->startColumn());
            }
            $result = Expr::binary('.', $result, $parts[$k], $bSpan);
            $k = $k + 1;
        }
        return $result;
    }

    /** Literal run of an interpolated string, [$from, $to) in body offsets; leaves the cursor at its end. */
    private function interpLit(string $lex, string $lit, int $from, int $to, Span $span, bool $exact): Expr
    {
        if (!$exact) { return Expr::string($lit, $span); }
        $this->interpAt($lex, $from + 1);
        $sl = $this->ipLine;
        $sc = $this->ipCol;
        $this->interpAt($lex, $to + 1);
        return Expr::string($lit, $this->interpRange($span, $sl, $sc));
    }

    /** Move the interpolation cursor forward over the raw lexeme to byte offset $off, counting lines and byte columns like the lexer. */
    private function interpAt(string $lex, int $off): void
    {
        $i = $this->ipOff;
        $l = $this->ipLine;
        $c = $this->ipCol;
        while ($i < $off) {
            if (\ord(\substr($lex, $i, 1)) === 10) { $l = $l + 1; $c = 1; } else { $c = $c + 1; }
            $i = $i + 1;
        }
        $this->ipOff = $i;
        $this->ipLine = $l;
        $this->ipCol = $c;
    }

    /** Range from ($sl, $sc) to the cursor, anchored at its start. */
    private function interpRange(Span $whole, int $sl, int $sc): Span
    {
        return new RangedSpan($sl, $sc, $whole->file, $this->ipLine, $this->ipCol, $sl, $sc);
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

    /**
     * Sub-lex + parse an interpolation `{$expr}` body. With a position base
     * ($line > 0, tolerant + ranges) the body is lexed where it sits in the file
     * and its names are collected; otherwise its positions are relative to the
     * body and every node carries the whole string's range.
     */
    private function parseSubExpr(string $src, Span $span, int $line = 0, int $col = 0): Expr
    {
        if ($line > 0) {
            $sub = new self((new Lexer())->scan($src, true, false, true, $line, $col), '', $this->sourceFile);
            $sub->ranges = 1;
            $sub->ends = 1;
            $sub->tolerant = 1;
            $sub->depth = $this->depth;
            $sub->collectNames = $this->collectNames;
            $sub->declNames = $this->declNames;
            $sub->currentNamespace = $this->currentNamespace;
            $sub->useAliases = $this->useAliases;
            $sub->useFnAliases = $this->useFnAliases;
            $sub->useConstAliases = $this->useConstAliases;
            $inner = $sub->parseExpression();
            foreach ($sub->names as $key => $ref) { $this->names[$key] = $ref; }
            foreach ($sub->errors as $e) { $this->record($e); }
            return $inner;
        }
        $tokens = (new Lexer())->scan($src, true, false, $this->ranges === 1);
        $sub = new self($tokens);
        $sub->ranges = $this->ranges;
        $sub->ends = $this->ranges;
        $sub->rangeOverride = $span;
        if ($this->tolerant === 1) {
            $sub->tolerant = 1;
            $sub->depth = $this->depth;
        }
        $inner = $sub->parseExpression();
        // sub-parser positions are relative to the string body: report them at the string
        foreach ($sub->errors as $e) {
            $this->record(new ParseError($e->reason, $span->line, $span->column, $span->line, $span->column));
        }
        return $inner;
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
            if ($this->ends === 1) {
                $this->prevEndLine = $tok->endLine();
                $this->prevEndColumn = $tok->endColumn();
            }
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
            if ($this->tolerant === 1) {
                $this->record($this->error($message));
                $t = $this->tokens[$this->pos];
                if ($this->ranges === 1 && ($kind === TokenKind::Variable || $kind === TokenKind::Identifier)) {
                    // a missing NAME becomes a node's content (Param, class, function): enclosing ranges must cover its point
                    $this->synthLine = $t->line;
                    $this->synthColumn = $t->column;
                }
                if ($this->ends === 1) { return new RangedToken($kind, '', $t->line, $t->column, $t->line, $t->column); }
                return new Token($kind, '', $t->line, $t->column);
            }
            throw $this->error($message);
        }
        return $this->advance();
    }

    /** Tolerant mode: append $e to the error list; the 101st entry is the one "too many errors" and later ones are dropped. */
    private function record(ParseError $e): void
    {
        $this->recorded = $this->recorded + 1;
        $n = \count($this->errors);
        for ($i = 0; $i < $n; $i = $i + 1) {
            if ($this->errors[$i]->errLine === $e->errLine && $this->errors[$i]->column === $e->column) { return; }
        }
        if ($n >= 100) {
            $this->capped = 1;
            return;
        }
        $this->errors[] = $e;
    }

    /** Tolerant mode: whether the list being parsed ends here — its closer, or a token that can only mean the closer is missing. */
    private function listEnds(string $close): bool
    {
        if ($this->check($close)) { return true; }
        if ($this->tolerant !== 1) { return false; }
        $k = $this->tokens[$this->pos]->kind;
        return $k === TokenKind::Semicolon || $k === TokenKind::CloseBrace || $k === TokenKind::Eof;
    }

    /** Nesting cap in tolerant mode, one unit per level (a statement, a block-or-statement body, an else-if arm, an expression re-entering the operator ladder, a prefix-operator operand, a DNF group). Deeper input throws ONE ParseError, which unwinds to the nearest statement boundary and is skipped from there. */
    private const MAX_DEPTH = 1000;

    /**
     * Tolerant statement boundary. A ParseError (a construct that cannot recover
     * in place) or a statement that consumed nothing is recorded, the tokens up
     * to the next synchronisation point are skipped, and the skipped range
     * becomes one ErrorStmt. The partial statement is discarded.
     */
    private function parseStatementOrRecover(): Stmt
    {
        $start = $this->pos;
        $anchor = $this->span();
        $hoisted = \count($this->hoistedClasses);
        $depth = $this->depth;
        $stmt = null;
        $thrown = false;
        if ($depth > self::MAX_DEPTH) {
            $this->record($this->error('nesting too deep'));
            $thrown = true;
        } else {
            $this->depth = $depth + 1;
            try {
                $stmt = $this->parseStatement();
            } catch (ParseError $e) {
                $this->record($e);
                $thrown = true;
            }
            $this->depth = $depth;
        }
        if (!$thrown && $stmt !== null && $this->pos !== $start) { return $stmt; }
        if ($thrown) {
            if (\count($this->hoistedClasses) > $hoisted) {
                $this->hoistedClasses = \array_slice($this->hoistedClasses, 0, $hoisted);
            }
            $this->skipToSync($start, false);
        } else {
            $this->record($this->error('unexpected token: ' . $this->tokens[$this->pos]->kind));
            $this->advance();
        }
        return new \Parser\Ast\ErrorStmt($this->endFrom($anchor, $this->tokens[$start]), $this->pos - $start, $thrown ? $this->declNameAt($start) : null);
    }

    /** Best-effort name of a declaration whose header failed: `[modifiers] class|interface|trait|enum|function [&] Name` at token $i. */
    private function declNameAt(int $i): ?string
    {
        $n = \count($this->tokens);
        while ($i < $n && $this->tokens[$i]->kind === TokenKind::Keyword) {
            $kw = \strtolower($this->tokens[$i]->lexeme);
            if ($kw === 'final' || $kw === 'abstract' || $kw === 'readonly') { $i = $i + 1; continue; }
            if ($kw !== 'class' && $kw !== 'interface' && $kw !== 'trait' && $kw !== 'enum' && $kw !== 'function') { return null; }
            $i = $i + 1;
            if ($i < $n && $this->tokens[$i]->kind === TokenKind::Identifier) { return $this->tokens[$i]->lexeme; }
            return null;
        }
        return null;
    }

    /**
     * Skip from the failure point to a synchronisation point. Braces opened
     * between $start and the failure point (a match, a closure body, a use group)
     * are still open: the skip runs until they close, and a `}` that closes one
     * ends the range, consumed. Past the failure point, parentheses and brackets
     * are counted too, so a `;` inside `for (;;)` or an argument list does not end
     * it. At brace depth 0 and outside any paren, `;` ends the range and is
     * consumed; `}` is the enclosing body's closer and stays; a statement keyword
     * (member keyword when $member) stays. At least one token is consumed when
     * nothing was since $start.
     */
    private function skipToSync(int $start, bool $member): void
    {
        if ($this->pos === $start) { $this->advance(); }
        $depth = 0;
        for ($i = $start; $i < $this->pos; $i = $i + 1) {
            $bk = $this->tokens[$i]->kind;
            if ($bk === TokenKind::OpenBrace && !$this->opensUseGroup($i)) { $depth = $depth + 1; }
            elseif ($bk === TokenKind::CloseBrace && $depth > 0) { $depth = $depth - 1; }
        }
        $nest = 0;
        while (!$this->isAtEnd()) {
            $t = $this->tokens[$this->pos];
            $k = $t->kind;
            if ($k === TokenKind::OpenParen || $k === TokenKind::OpenBracket || $k === TokenKind::AttributeStart) {
                $nest = $nest + 1;
            } elseif (($k === TokenKind::CloseParen || $k === TokenKind::CloseBracket) && $nest > 0) {
                $nest = $nest - 1;
            } elseif ($k === TokenKind::OpenBrace) {
                if (!$this->opensUseGroup($this->pos)) { $depth = $depth + 1; }
            } elseif ($k === TokenKind::CloseBrace) {
                if ($depth === 0) { return; }
                $depth = $depth - 1;
                $this->advance();
                if ($depth === 0) { return; }
                continue;
            } elseif ($depth === 0 && $nest === 0) {
                if ($k === TokenKind::Semicolon) { $this->advance(); return; }
                if ($k === TokenKind::Keyword && $this->isSyncKeyword(\strtolower($t->lexeme), $member)) { return; }
            }
            $this->advance();
        }
    }

    /** Whether the `{` at token index $i opens a use group (`use A\{B, C}`): a bad group is ended by its `;`, not by a closer. */
    private function opensUseGroup(int $i): bool
    {
        return $i > 0 && $this->tokens[$i - 1]->kind === TokenKind::Backslash;
    }

    private function isSyncKeyword(string $kw, bool $member): bool
    {
        if ($member) {
            return $kw === 'public' || $kw === 'private' || $kw === 'protected' || $kw === 'static'
                || $kw === 'abstract' || $kw === 'final' || $kw === 'readonly' || $kw === 'const'
                || $kw === 'function' || $kw === 'use' || $kw === 'case' || $kw === 'var';
        }
        return $kw === 'if' || $kw === 'while' || $kw === 'for' || $kw === 'foreach' || $kw === 'switch'
            || $kw === 'return' || $kw === 'echo' || $kw === 'function' || $kw === 'class'
            || $kw === 'interface' || $kw === 'trait' || $kw === 'enum' || $kw === 'abstract'
            || $kw === 'final' || $kw === 'namespace' || $kw === 'use' || $kw === 'try' || $kw === 'throw'
            || $kw === 'break' || $kw === 'continue' || $kw === 'global' || $kw === 'do'
            || $kw === 'declare' || $kw === 'const' || $kw === 'goto';
    }

    /**
     * Zero-width range at the current token's start for a node standing for
     * missing text. Records the point so every enclosing range grows to cover it.
     */
    private function missingAt(Span $anchor): Span
    {
        if ($this->ranges !== 1) { return $anchor; }
        $t = $this->tokens[$this->pos];
        $this->synthLine = $t->line;
        $this->synthColumn = $t->column;
        return new RangedSpan($anchor->line, $anchor->column, $anchor->file, $t->line, $t->column, $t->line, $t->column);
    }

    /**
     * A MEMBER name — a class constant or an enum case.
     *
     * php lets a reserved word stand here: `case ARRAY = 'array';` in
     * doctrine/dbal's ArrayParameterType, `const LIST = …`, `case MATCH`. The
     * lexer classifies those as Keyword, not Identifier, so requiring an
     * Identifier rejected code the interpreter accepts — six sites across
     * doctrine/dbal and nette/utils in symfony-demo alone.
     *
     * Deliberately NOT used for a plain identifier position: `class array` is
     * still a syntax error in php, and accepting it here would only turn a
     * clean parse error into a confusing one further along.
     */
    private function expectMemberName(string $message): Token
    {
        if ($this->check(TokenKind::Identifier) || $this->check(TokenKind::Keyword)) {
            return $this->advance();
        }
        throw $this->error($message);
    }

    /**
     * Position of the current token, MEMOISED on the token index.
     *
     * Every parse entry point asks for the span of the token it starts on, and
     * the nested calls under it ask again at the same index — an expression
     * statement alone mints one Span per level. The object is readonly, so the
     * whole nest can share ONE: on a symfony-sized build Span was 1.5 M
     * allocations with 14 k frees (the census in
     * `docs/status/T6-MEMORY-HANDOFF-2026-09-04.md`).
     *
     * Keyed on the index alone; {@see reset} clears it, which is the only way
     * an index can come to mean a different token (a new file, new tokens).
     */
    private int $spanPos = -1;
    private ?Span $spanMemo = null;

    private function span(): Span
    {
        if ($this->pos === $this->spanPos && $this->spanMemo !== null) {
            return $this->spanMemo;
        }
        $tok = $this->tokens[$this->pos];
        $s = new Span($tok->line, $tok->column, $this->sourceFile);
        $this->spanPos = $this->pos;
        $this->spanMemo = $s;
        return $s;
    }

    /** End of the last consumed token: where a node completing now ends. */
    private int $prevEndLine = 0;
    private int $prevEndColumn = 0;

    /** Position of the latest zero-width Error node; an enclosing range ends no earlier than it (ranges + tolerant). */
    private int $synthLine = 0;
    private int $synthColumn = 0;

    /**
     * Range of an interpolated-string sub-expression: its tokens are lexed from
     * the string BODY, so their positions are meaningless in the file. Every node
     * the sub-parser builds carries the whole string's range instead (the anchor
     * line/column stay as lexed — `dump-ast` prints them).
     */
    private ?Span $rangeOverride = null;

    /** Whether nodes get real ranges; off (the compiler's path) every helper hands back the anchor untouched. */
    private int $ranges = 0;

    /**
     * $anchor closed at the last consumed token. $from is the leftmost operand
     * when the anchor is an operator or bracket (binary, ternary, assignment,
     * postfix): the range then starts at the operand, the anchor stays what
     * `dump-ast` prints.
     */
    private function end(Span $anchor, ?Expr $from = null): Span
    {
        if ($this->ranges !== 1) { return $anchor; }
        $o = $this->rangeOverride;
        if ($o !== null) {
            return new RangedSpan($anchor->line, $anchor->column, $anchor->file, $o->endLine(), $o->endColumn(), $o->startLine(), $o->startColumn());
        }
        $bl = 0;
        $bc = 0;
        if ($from !== null) {
            $bl = $from->span->startLine();
            $bc = $from->span->startColumn();
        }
        $el = $this->prevEndLine;
        $ec = $this->prevEndColumn;
        if ($this->synthLine > $el || ($this->synthLine === $el && $this->synthColumn > $ec)) {
            $el = $this->synthLine;
            $ec = $this->synthColumn;
        }
        $sl = $bl > 0 ? $bl : $anchor->line;
        $sc = $bl > 0 ? $bc : $anchor->column;
        if ($el < $sl || ($el === $sl && $ec < $sc)) { $el = $sl; $ec = $sc; }   // consumed nothing: zero-width at its start
        return new RangedSpan($anchor->line, $anchor->column, $anchor->file, $el, $ec, $bl, $bc);
    }

    /** $anchor closed at the last consumed token, with the range starting at token $first. */
    private function endFrom(Span $anchor, Token $first): Span
    {
        if ($this->ranges !== 1) { return $anchor; }
        $o = $this->rangeOverride;
        if ($o !== null) {
            return $this->end($anchor);
        }
        $el = $this->prevEndLine;
        $ec = $this->prevEndColumn;
        if ($this->synthLine > $el || ($this->synthLine === $el && $this->synthColumn > $ec)) {
            $el = $this->synthLine;
            $ec = $this->synthColumn;
        }
        if ($el < $first->line || ($el === $first->line && $ec < $first->column)) { $el = $first->line; $ec = $first->column; }
        return new RangedSpan($anchor->line, $anchor->column, $anchor->file, $el, $ec, $first->line, $first->column);
    }

    /** Range of the single token $t under another node's anchor (dump-ast keeps the anchor). */
    private function tokSpan(Span $anchor, Token $t): Span
    {
        if ($this->ranges !== 1) { return $anchor; }
        $o = $this->rangeOverride;
        if ($o !== null) {
            return $this->end($anchor);
        }
        return new RangedSpan($anchor->line, $anchor->column, $anchor->file, $t->endLine(), $t->endColumn(), $t->line, $t->column);
    }

    /** Range of the synthesized node standing for exactly $child's text. */
    private function wrapSpan(Span $anchor, Expr $child): Span
    {
        if ($this->ranges !== 1) { return $anchor; }
        $c = $child->span;
        return new RangedSpan($anchor->line, $anchor->column, $anchor->file, $c->endLine(), $c->endColumn(), $c->startLine(), $c->startColumn());
    }

    /** Zero-width range at the start of $anchor, for synthesized nodes that stand for no source text. */
    private function emptyAt(Span $anchor): Span
    {
        if ($this->ranges !== 1) { return $anchor; }
        return new RangedSpan($anchor->line, $anchor->column, $anchor->file, $anchor->line, $anchor->column);
    }

    /** Zero-width range at token $at under another node's anchor (a destructuring hole). */
    private function emptyAtTok(Span $anchor, Token $at): Span
    {
        if ($this->ranges !== 1) { return $anchor; }
        return new RangedSpan($anchor->line, $anchor->column, $anchor->file, $at->line, $at->column, $at->line, $at->column);
    }

    /** Range of an alternative-syntax or single-statement body: from token $first to the end ($el, $ec) of its last statement; a zero-width point at ($el, $ec) when there is none. */
    private function listSpan(?Token $first, int $el, int $ec): ?Span
    {
        if ($this->ranges !== 1) { return null; }
        $file = $this->sourceFile;
        if ($first === null) {
            return new RangedSpan($el, $ec, $file, $el, $ec);
        }
        if ($el < $first->line || ($el === $first->line && $ec < $first->column)) {
            return new RangedSpan($el, $ec, $file, $el, $ec);
        }
        return new RangedSpan($first->line, $first->column, $file, $el, $ec);
    }

    /** Range of the synthesized body holding only statement $s. */
    private function wrapStmtSpan(Stmt $s): ?Span
    {
        if ($this->ranges !== 1) { return null; }
        $c = $s->span;
        return new RangedSpan($c->startLine(), $c->startColumn(), $c->file, $c->endLine(), $c->endColumn());
    }

    /** Move the cursor back to token index $p, keeping the previous-token end consistent and any synthetic point at or before it. */
    private function rewind(int $p): void
    {
        $this->pos = $p;
        $at = $this->tokens[$p];
        if ($this->synthLine > $at->line || ($this->synthLine === $at->line && $this->synthColumn > $at->column)) {
            $this->synthLine = 0;
            $this->synthColumn = 0;
        }
        $prev = $p > 0 ? $this->tokens[$p - 1] : null;
        if ($this->ends === 1) {
            $this->prevEndLine = $prev !== null ? $prev->endLine() : 0;
            $this->prevEndColumn = $prev !== null ? $prev->endColumn() : 0;
        }
    }

    private function error(string $message): ParseError
    {
        $tok = $this->tokens[$this->pos];
        $el = $tok->endLine();
        $ec = $tok->endColumn();
        if ($el === 0 && $this->tolerant === 1) {
            // unranged lexer: the end follows from the lexeme (single-line: start + length, multi-line: last-line
            // length). Approximate for a heredoc/comment lexeme the lexer normalised; only analyze lands here and prints no end.
            $nl = \substr_count($tok->lexeme, "\n");
            $el = $tok->line + $nl;
            $ec = $nl === 0 ? $tok->column + \strlen($tok->lexeme) : \strlen($tok->lexeme) - (int)\strrpos($tok->lexeme, "\n");
        }
        return new ParseError($message, $tok->line, $tok->column, $el, $ec);
    }
}
