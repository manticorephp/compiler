<?php

declare(strict_types=1);

namespace Compile\Mir\Passes;

/**
 * Attribute-name resolution and the PHP reserved-attribute checks, mixed into
 * `LowerFromAst`.
 *
 * `AttributeNode::$name` is ALREADY a fully-qualified name: the parser routes
 * attribute names through `parseClassName()` → `resolveClassName()`, which
 * strips a leading `\`, expands `use X as Y` aliases, and prepends the current
 * namespace. The only thing missing was one shared accessor — every consumer
 * used to hand-roll `ltrim($attr->name, '\\')` off an untyped loop variable,
 * which is a T5 offset hazard under self-host.
 */
trait LowerAttrChecks
{
    /** Canonical attribute-class name: FQN, no leading backslash. Read through
     *  a typed param — a base-typed `->name` resolves by OFFSET under self-host. */
    private function attrFqn(\Parser\Ast\AttributeNode $a): string
    {
        return \ltrim($a->name, '\\');
    }

    /** An attribute's argument list, likewise through a typed param.
     *  @return \Parser\Ast\Expr[] */
    private function attrArgs(\Parser\Ast\AttributeNode $a): array
    {
        return $a->args;
    }

    /**
     * Whether an attribute names one of `$accepted` (each already canonical).
     * The compiler's own markers accept a bare, an `Attr\` and a
     * `Manticore\Attr\` spelling because `src/` is parsed both with and without
     * namespaces across the seed and self-host paths.
     *
     * @param string[] $accepted
     */
    private function attrIsOneOf(\Parser\Ast\AttributeNode $a, array $accepted): bool
    {
        $name = $this->attrFqn($a);
        foreach ($accepted as $want) {
            if ($name === $want) { return true; }
        }
        return false;
    }

    /**
     * The PHP reserved attribute this occurrence names, or '' when it names
     * none. Global namespace only: a `Foo\Override` is an unrelated userland
     * attribute and must never be validated against the reserved rules.
     */
    private function reservedAttr(\Parser\Ast\AttributeNode $a): string
    {
        $name = $this->attrFqn($a);
        if (\strpos($name, '\\') !== false) { return ''; }
        return \Compile\BuiltinAttributes::isReserved($name) ? $name : '';
    }

    // ── reserved-attribute checks ────────────────────────────────────────────

    /** Collected `line N: error: …` diagnostics when {@see $attrCollectMode}. */
    public array $attrErrors = [];
    /** Analyze mode: collect instead of aborting the build. */
    public bool $attrCollectMode = false;

    /**
     * Validate every reserved-attribute USE in the user program. Runs right
     * after the class-registration pre-pass: `$classDecls` (classes, interfaces
     * AND enums, each with its span) and `$traitTable` are complete, no ClassDef
     * exists yet and no body has been lowered, so a fatal aborts early. It is
     * also the ONLY place interfaces are visible — they never get a ClassDef.
     *
     * Only the 8 RESERVED attributes are checked here, and that mirrors Zend
     * exactly: a userland `#[X]` on the wrong target compiles clean and only
     * fails at `ReflectionAttribute::newInstance()`. Which is what lets the
     * compiler's own `#[Symbol]` / `#[RefOut]` / `#[TypeDef]` markers through.
     *
     * @param \Parser\Ast\Stmt[] $stmts  prelude ++ user, already ns-flattened
     * @param int $preludeCount          index where the user program starts
     */
    private function checkAttributes(array $stmts, int $preludeCount): void
    {
        $i = -1;
        foreach ($stmts as $stmt) {
            $i = $i + 1;
            if ($i < $preludeCount) { continue; }
            if ($stmt->kind === 'Class') {
                $this->checkClassDeclAttrs($this->classStmtDecl($stmt));
            } elseif ($stmt->kind === 'Expression') {
                // A top-level `const NAME = …;` desugars to `define()`, so its
                // attributes ride on the expression statement.
                $attrs = $this->exprStmtAttrs($stmt);
                if ($attrs !== []) {
                    $this->checkAttrSite($attrs, \Compile\BuiltinAttributes::TARGET_CONSTANT,
                        $this->stmtSpan($stmt));
                    $cn = $this->definedConstName($stmt);
                    if ($cn !== '') {
                        $dep = $this->deprecatedText($attrs, 'Constant ' . $cn);
                        if ($dep !== '') { $this->deprecatedConsts[$cn] = $dep; }
                    }
                }
            } elseif ($stmt->kind === 'Function') {
                $decl = $this->fnStmtDecl($stmt);
                $this->checkAttrSite($this->fnDeclAttrs($decl),
                    \Compile\BuiltinAttributes::TARGET_FUNCTION, $this->fnDeclSpan($decl));
                $this->recordFnDiagnostics($decl);
                foreach ($this->fnDeclParams($decl) as $p) {
                    $this->checkAttrSite($this->paramAttrs($p),
                        \Compile\BuiltinAttributes::TARGET_PARAMETER, $this->paramSpan($p));
                }
            }
        }
    }

    /** Every attribute site a class declaration owns, plus the `#[Override]` checks. */
    private function checkClassDeclAttrs(\Parser\Ast\ClassDecl $d): void
    {
        $this->checkAttrSite($d->attributes, \Compile\BuiltinAttributes::TARGET_CLASS, $d->span);
        // Zend rejects #[\Deprecated] on a class outright, even though
        // TARGET_CLASS is in the attribute's own flag set.
        foreach ($d->attributes as $a) {
            if ($this->reservedAttr($a) === 'Deprecated') {
                $this->attrFail('Cannot apply #[\\Deprecated] to class '
                    . \ltrim($d->name, '\\'), $d->span);
            }
        }
        foreach ($d->methods as $m) {
            $this->checkAttrSite($this->methodAttrs($m),
                \Compile\BuiltinAttributes::TARGET_METHOD, $this->methodSpan($m));
            foreach ($this->methodDeclParams($m) as $p) {
                $this->checkAttrSite($this->paramAttrs($p),
                    \Compile\BuiltinAttributes::TARGET_PARAMETER, $this->paramSpan($p));
                // A promoted constructor parameter is also a PROPERTY site.
                if ($this->paramPromoted($p) !== '') {
                    $this->checkAttrSite($this->paramAttrs($p),
                        \Compile\BuiltinAttributes::TARGET_PROPERTY, $this->paramSpan($p));
                }
            }
            $this->recordMethodDiagnostics($d, $m);
            if ($this->hasOverride($this->methodAttrs($m))
                && !$this->ancestorHasMethod($d, $this->methodDeclName($m))) {
                $this->attrFail(\ltrim($d->name, '\\') . '::' . $this->methodDeclName($m)
                    . '() has #[\\Override] attribute, but no matching parent method exists',
                    $this->methodSpan($m));
            }
        }
        foreach ($d->properties as $p) {
            $this->checkAttrSite($this->propAttrs($p),
                \Compile\BuiltinAttributes::TARGET_PROPERTY, $this->propSpan($p));
            if ($this->hasOverride($this->propAttrs($p))
                && !$this->ancestorHasProperty($d, $this->propName($p))) {
                $this->attrFail(\ltrim($d->name, '\\') . '::$' . $this->propName($p)
                    . ' has #[\\Override] attribute, but no matching parent property exists',
                    $this->propSpan($p));
            }
        }
        $cls = \ltrim($this->declName($d), '\\');
        foreach ($d->consts as $c) {
            $this->checkAttrSite($this->constAttrs($c),
                \Compile\BuiltinAttributes::TARGET_CLASS_CONSTANT, $this->constSpan($c));
            $cn = $this->constDeclName($c);
            $dep = $this->deprecatedText($this->constAttrs($c), 'Constant ' . $cls . '::' . $cn);
            if ($dep !== '') { $this->deprecatedConsts[$cls . '::' . $cn] = $dep; }
        }
        // An enum case reports TARGET_CLASS_CONSTANT, not TARGET_CLASS (probed).
        foreach ($d->cases as $c) {
            $cspan = $this->enumCaseSpan($c);
            $this->checkAttrSite($this->enumCaseAttrs($c),
                \Compile\BuiltinAttributes::TARGET_CLASS_CONSTANT,
                $cspan === null ? $d->span : $cspan);
            $en = $this->enumCaseName($c);
            $dep = $this->deprecatedText($this->enumCaseAttrs($c), 'Enum case ' . $cls . '::' . $en);
            if ($dep !== '') { $this->deprecatedConsts[$cls . '::' . $en] = $dep; }
        }
    }

    /**
     * Target + repeat validation for one declaration site.
     *
     * `#[DelayedTargetValidation]` anywhere at the site suppresses BOTH checks
     * for every attribute there — Zend then re-runs them inside newInstance().
     *
     * @param \Parser\Ast\AttributeNode[] $attrs
     */
    private function checkAttrSite(array $attrs, int $target, \Parser\Ast\Span $span): void
    {
        $delayed = false;
        foreach ($attrs as $a) {
            if ($this->reservedAttr($a) === 'DelayedTargetValidation') { $delayed = true; }
        }
        if ($delayed) { return; }
        $seen = [];
        foreach ($attrs as $a) {
            $name = $this->reservedAttr($a);
            if ($name === '') { continue; }
            $flags = \Compile\BuiltinAttributes::flagsOf($name);
            if (($flags & $target) === 0) {
                $this->attrFail('Attribute "' . $name . '" cannot target '
                    . \Compile\BuiltinAttributes::targetWord($target)
                    . ' (allowed targets: ' . \Compile\BuiltinAttributes::allowedList($flags) . ')',
                    $span);
            }
            // No reserved attribute is IS_REPEATABLE.
            if (isset($seen[$name])) {
                $this->attrFail('Attribute "' . $name . '" must not be repeated', $span);
            }
            $seen[$name] = true;
        }
    }

    // ── #[\Deprecated] / #[\NoDiscard] message tables ───────────────────────

    /**
     * The tail php appends after "… is deprecated": ` since <v>` then
     * `, <message>`, each optional. Constructor order is
     * `(?string $message, ?string $since)`, so positional 0 is the MESSAGE.
     * Only string literals are read; anything else is ignored.
     */
    private function attrStrArgs(\Parser\Ast\AttributeNode $a): array
    {
        /** @var string[] $pos */
        $pos = [];
        /** @var array<string,string> $named */
        $named = [];
        foreach ($this->attrArgs($a) as $arg) {
            if ($arg->kind === 'NamedArg') {
                $v = $this->namedArgValue($arg);
                if ($v->kind === 'StringLiteral') { $named[$this->namedArgName($arg)] = $this->strLitValue($v); }
                continue;
            }
            if ($arg->kind === 'StringLiteral') { $pos[] = $this->strLitValue($arg); }
        }
        $message = $named['message'] ?? ($pos[0] ?? '');
        $since = $named['since'] ?? ($pos[1] ?? '');
        return ['message' => $message, 'since' => $since];
    }

    /** @param \Parser\Ast\AttributeNode[] $attrs */
    private function deprecatedText(array $attrs, string $subject): string
    {
        foreach ($attrs as $a) {
            if ($this->reservedAttr($a) !== 'Deprecated') { continue; }
            $parts = $this->attrStrArgs($a);
            $out = $subject . ' is deprecated';
            if ($parts['since'] !== '') { $out = $out . ' since ' . $parts['since']; }
            if ($parts['message'] !== '') { $out = $out . ', ' . $parts['message']; }
            return $out;
        }
        return '';
    }

    /** @param \Parser\Ast\AttributeNode[] $attrs */
    private function noDiscardText(array $attrs, string $subject): string
    {
        foreach ($attrs as $a) {
            if ($this->reservedAttr($a) !== 'NoDiscard') { continue; }
            $parts = $this->attrStrArgs($a);
            $out = 'The return value of ' . $subject
                 . ' should either be used or intentionally ignored by casting it as (void)';
            if ($parts['message'] !== '') { $out = $out . ', ' . $parts['message']; }
            return $out;
        }
        return '';
    }

    /** "Class::NAME" (class const / enum case) or "NAME" (global const) → the
     *  `#[\Deprecated]` body. Constants inline at LOWERING, so unlike calls they
     *  have no node left at emit time to hang a diagnostic on.
     *  @var array<string, string> */
    private array $deprecatedConsts = [];

    /**
     * Queue the diagnostic for a deprecated constant USE. It rides
     * `$pendingCallInits`, which `lowerStmt` flushes immediately before the
     * enclosing statement — matching php, which prints the notice before the
     * statement's own output.
     */
    private function noteDeprecatedConstUse(string $key, int $line): void
    {
        $text = $this->deprecatedConsts[$key] ?? '';
        if ($text === '') { return; }
        $mod = $this->module;
        $file = $mod === null ? '' : $mod->sourceFile;
        $this->pendingCallInits[] = new \Compile\Mir\Echo_(
            [new \Compile\Mir\StringConst(
                "\nDeprecated: " . $text . ' in ' . $file . ' on line ' . (string)$line . "\n",
                \Compile\Mir\Type::string_(),
            )],
            \Compile\Mir\Type::void(),
        );
    }

    private function recordFnDiagnostics(\Parser\Ast\FunctionDecl $d): void
    {
        $mod = $this->module;
        if ($mod === null) { return; }
        $name = \ltrim($this->fnDeclName($d), '\\');
        $attrs = $this->fnDeclAttrs($d);
        $dep = $this->deprecatedText($attrs, 'Function ' . $name . '()');
        if ($dep !== '') { $mod->deprecatedFns[$name] = $dep; }
        $nd = $this->noDiscardText($attrs, 'function ' . $name . '()');
        if ($nd !== '') { $mod->noDiscardFns[$name] = $nd; }
    }

    private function recordMethodDiagnostics(\Parser\Ast\ClassDecl $c, \Parser\Ast\MethodDecl $m): void
    {
        $mod = $this->module;
        if ($mod === null) { return; }
        $cls = \ltrim($this->declName($c), '\\');
        $mn = $this->methodDeclName($m);
        $attrs = $this->methodAttrs($m);
        $dep = $this->deprecatedText($attrs, 'Method ' . $cls . '::' . $mn . '()');
        if ($dep !== '') { $mod->deprecatedMethods[$cls . '::' . $mn] = $dep; }
        // An ABSTRACT or interface declaration does NOT propagate #[\NoDiscard]
        // to the concrete implementation — php warns from the declaration that
        // actually runs.
        if ($this->methodDeclBodyIsNull($m)) { return; }
        $nd = $this->noDiscardText($attrs, 'method ' . $cls . '::' . $mn . '()');
        if ($nd !== '') { $mod->noDiscardMethods[$cls . '::' . $mn] = $nd; }
    }

    /** @param \Parser\Ast\AttributeNode[] $attrs */
    private function hasOverride(array $attrs): bool
    {
        foreach ($attrs as $a) {
            if ($this->reservedAttr($a) === 'Override') { return true; }
        }
        return false;
    }

    /**
     * Whether a PARENT class, one of its traits, or any implemented interface
     * declares `$method`. The class's OWN traits deliberately do not count —
     * Zend rejects `#[\Override]` on a method that only mixes in from a trait
     * used by the same class. `__construct` is not exempt.
     */
    private function ancestorHasMethod(\Parser\Ast\ClassDecl $d, string $method): bool
    {
        foreach ($this->ancestorDecls($d) as $a) {
            foreach ($this->classDeclMethods($a) as $m) {
                if (\strtolower($this->methodDeclName($m)) === \strtolower($method)) { return true; }
            }
        }
        return false;
    }

    /** As {@see ancestorHasMethod}, over declared properties + promoted ctor params. */
    private function ancestorHasProperty(\Parser\Ast\ClassDecl $d, string $prop): bool
    {
        foreach ($this->ancestorDecls($d) as $a) {
            foreach ($this->classDeclProperties($a) as $p) {
                if ($this->propName($p) === $prop) { return true; }
            }
            foreach ($this->classDeclMethods($a) as $m) {
                if ($this->methodDeclName($m) !== '__construct') { continue; }
                foreach ($this->methodDeclParams($m) as $par) {
                    if ($this->paramPromoted($par) !== ''
                        && $this->paramName($par) === $prop) { return true; }
                }
            }
        }
        return false;
    }

    /**
     * Every declaration `$d` inherits from — NOT including `$d` itself: the
     * transitive extends chain, each of THOSE classes' traits, and the
     * transitive interface closure.
     *
     * The worklist holds NAMES only, walked with a cursor. A queue mixing
     * strings and ClassDecls would be a union-typed array (erased element repr),
     * and `array_shift` on it read the wrong thing natively — every #[\Override]
     * silently found a "match" and no error ever fired, while Zend was correct.
     *
     * Bounded by the size of the decl tables so a cyclic `extends` in a
     * malformed program cannot hang the compiler.
     *
     * @return \Parser\Ast\ClassDecl[]
     */
    private function ancestorDecls(\Parser\Ast\ClassDecl $d): array
    {
        /** @var string[] $queue */
        $queue = [];
        foreach ($this->classDeclExtends($d) as $e) { $queue[] = \ltrim($e, '\\'); }
        foreach ($this->classDeclImplements($d) as $ifc) { $queue[] = \ltrim($ifc, '\\'); }
        /** @var \Parser\Ast\ClassDecl[] $out */
        $out = [];
        /** @var array<string,bool> $seen */
        $seen = [];
        $budget = \count($this->classDecls) + \count($this->traitTable) + 2;
        $i = 0;
        while ($i < \count($queue) && $budget > 0) {
            $budget = $budget - 1;
            $name = $queue[$i];
            $i = $i + 1;
            if (isset($seen[$name])) { continue; }
            $seen[$name] = true;
            $decl = $this->classDecls[$name] ?? ($this->traitTable[$name] ?? null);
            if ($decl === null) { continue; }
            $out[] = $decl;
            foreach ($this->classDeclExtends($decl) as $e) { $queue[] = \ltrim($e, '\\'); }
            foreach ($this->classDeclImplements($decl) as $ifc) { $queue[] = \ltrim($ifc, '\\'); }
            foreach ($this->classDeclUses($decl) as $t) { $queue[] = \ltrim($t, '\\'); }
        }
        return $out;
    }

    /**
     * Report one attribute error. Analyze mode collects it in the
     * `line N: error: …` shape the driver already parses back into a
     * diagnostic; a normal build throws, and Main's catch prints it.
     */
    private function attrFail(string $msg, \Parser\Ast\Span $span): void
    {
        if ($this->attrCollectMode) {
            $this->attrErrors[] = 'line ' . (string)$span->line . ': error: ' . $msg;
            return;
        }
        throw new \RuntimeException($msg . ' at line ' . (string)$span->line);
    }

    // ── typed field reads (T5: a base-typed read resolves by OFFSET) ─────────

    private function classStmtDecl(\Parser\Ast\ClassStmt $s): \Parser\Ast\ClassDecl { return $s->decl; }
    private function fnStmtDecl(\Parser\Ast\FunctionStmt $s): \Parser\Ast\FunctionDecl { return $s->decl; }
    /** @return \Parser\Ast\AttributeNode[] */
    private function fnDeclAttrs(\Parser\Ast\FunctionDecl $d): array { return $d->attributes; }
    /** @return \Parser\Ast\Param[] */
    private function fnDeclParams(\Parser\Ast\FunctionDecl $d): array { return $d->params; }
    private function fnDeclSpan(\Parser\Ast\FunctionDecl $d): \Parser\Ast\Span { return $d->span; }
    /** @return \Parser\Ast\AttributeNode[] */
    private function methodAttrs(\Parser\Ast\MethodDecl $m): array { return $m->attributes; }
    private function methodSpan(\Parser\Ast\MethodDecl $m): \Parser\Ast\Span { return $m->span; }
    /** @return \Parser\Ast\AttributeNode[] */
    private function propAttrs(\Parser\Ast\PropertyDecl $p): array { return $p->attributes; }
    private function propSpan(\Parser\Ast\PropertyDecl $p): \Parser\Ast\Span { return $p->span; }
    private function propName(\Parser\Ast\PropertyDecl $p): string { return $p->name; }
    /** @return \Parser\Ast\AttributeNode[] */
    private function constAttrs(\Parser\Ast\ConstDecl $c): array { return $c->attributes; }
    /** @return \Parser\Ast\AttributeNode[] */
    private function paramAttrs(\Parser\Ast\Param $p): array { return $p->attributes; }
    private function paramPromoted(\Parser\Ast\Param $p): string { return $p->promoted; }
    private function paramSpan(\Parser\Ast\Param $p): \Parser\Ast\Span { return $p->span; }
    private function fnDeclName(\Parser\Ast\FunctionDecl $d): string { return $d->name; }
    private function constDeclName(\Parser\Ast\ConstDecl $c): string { return $c->name; }
    private function enumCaseName(\Parser\Ast\EnumCase $c): string { return $c->name; }

    /** The NAME a top-level `const X = …;` defines — the statement desugars to
     *  `define('X', …)`, so it is the call's first string-literal argument. */
    private function definedConstName(\Parser\Ast\ExpressionStmt $s): string
    {
        $e = $s->expr;
        if ($e->kind !== 'Call') { return ''; }
        $args = $this->callExprArgs($e);
        if ($args === []) { return ''; }
        $a0 = $args[0];
        if ($a0->kind !== 'StringLiteral') { return ''; }
        return $this->strLitValue($a0);
    }
    /** @return \Parser\Ast\Expr[] */
    private function callExprArgs(\Parser\Ast\CallExpr $c): array { return $c->args; }
    private function namedArgName(\Parser\Ast\NamedArg $a): string { return $a->name; }
    private function namedArgValue(\Parser\Ast\NamedArg $a): \Parser\Ast\Expr { return $a->value; }
    private function methodDeclBodyIsNull(\Parser\Ast\MethodDecl $m): bool { return $m->body === null; }
    /** @return \Parser\Ast\AttributeNode[] */
    private function enumCaseAttrs(\Parser\Ast\EnumCase $c): array { return $c->attributes; }
    private function enumCaseSpan(\Parser\Ast\EnumCase $c): ?\Parser\Ast\Span { return $c->span; }
    /** @return \Parser\Ast\AttributeNode[] */
    private function exprStmtAttrs(\Parser\Ast\ExpressionStmt $s): array { return $s->attributes; }
    private function stmtSpan(\Parser\Ast\Stmt $s): \Parser\Ast\Span { return $s->span; }
    /** @return \Parser\Ast\PropertyDecl[] */
    private function classDeclProperties(\Parser\Ast\ClassDecl $d): array { return $d->properties; }
    /** @return string[] */
    private function classDeclImplements(\Parser\Ast\ClassDecl $d): array { return $d->implements; }
    /** @return string[] */
    private function classDeclUses(\Parser\Ast\ClassDecl $d): array { return $d->uses; }
    private function constSpan(\Parser\Ast\ConstDecl $c): \Parser\Ast\Span { return $c->span; }
}
