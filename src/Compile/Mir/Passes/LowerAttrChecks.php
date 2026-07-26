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
            } elseif ($stmt->kind === 'Function') {
                $decl = $this->fnStmtDecl($stmt);
                $this->checkAttrSite($this->fnDeclAttrs($decl),
                    \Compile\BuiltinAttributes::TARGET_FUNCTION, $this->fnDeclSpan($decl));
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
        foreach ($d->consts as $c) {
            $this->checkAttrSite($this->constAttrs($c),
                \Compile\BuiltinAttributes::TARGET_CLASS_CONSTANT, $this->constSpan($c));
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
    /** @return \Parser\Ast\PropertyDecl[] */
    private function classDeclProperties(\Parser\Ast\ClassDecl $d): array { return $d->properties; }
    /** @return string[] */
    private function classDeclImplements(\Parser\Ast\ClassDecl $d): array { return $d->implements; }
    /** @return string[] */
    private function classDeclUses(\Parser\Ast\ClassDecl $d): array { return $d->uses; }
    private function constSpan(\Parser\Ast\ConstDecl $c): \Parser\Ast\Span { return $c->span; }
}
