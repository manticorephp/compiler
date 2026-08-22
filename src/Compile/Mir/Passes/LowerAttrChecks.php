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

    // ── Ffi\* attribute checks ───────────────────────────────────────────────

    /** The `Ffi\` attributes that only mean anything on an FFI binding. */
    private function ffiAttrKind(\Parser\Ast\AttributeNode $a): string
    {
        $n = $this->attrFqn($a);
        if ($n === 'Ffi\\Library'   || $n === 'Library')   { return 'Library'; }
        if ($n === 'Ffi\\Symbol'    || $n === 'Symbol')    { return 'Symbol'; }
        if ($n === 'Ffi\\CType'     || $n === 'CType')     { return 'CType'; }
        if ($n === 'Ffi\\Weak'      || $n === 'Weak')      { return 'Weak'; }
        if ($n === 'Ffi\\Variadic'  || $n === 'Variadic')  { return 'Variadic'; }
        if ($n === 'Ffi\\Borrow'    || $n === 'Borrow')    { return 'Borrow'; }
        if ($n === 'Ffi\\BorrowMut' || $n === 'BorrowMut') { return 'BorrowMut'; }
        if ($n === 'Ffi\\Take'      || $n === 'Take')      { return 'Take'; }
        if ($n === 'Ffi\\Give'      || $n === 'Give')      { return 'Give'; }
        if ($n === 'Ffi\\StaticPtr' || $n === 'StaticPtr') { return 'StaticPtr'; }
        return '';
    }

    /**
     * Validate the `Ffi\*` attribute uses across the whole program.
     *
     * The ownership family — Borrow / BorrowMut / Take / Give / StaticPtr — is
     * CHECKED here and lowered NOWHERE. Nothing is freed on your behalf, and
     * this pass does not change a byte of emitted code; it exists so the
     * attributes stop being decoration. Two of the rules are real safety rules
     * rather than tidiness: a PHP string and a C-owned buffer have opposite
     * memory disciplines, and writing `#[Take]` on a string or `#[Give]` on a
     * string return asks the runtime to hand a refcounted block to `free()` (or
     * to rc-release a block with no header), which corrupts the heap silently.
     *
     * @param \Parser\Ast\Stmt[] $stmts  prelude ++ user, already ns-flattened
     */
    private function checkFfiAttrs(array $stmts): void
    {
        foreach ($stmts as $stmt) {
            if ($stmt->kind === 'Function') {
                $this->checkFfiBinding($this->fnStmtDecl($stmt));
            } elseif ($stmt->kind === 'Class') {
                $this->checkFfiOnClass($this->classStmtDecl($stmt));
            }
        }
    }

    /**
     * An FFI binding must be a FREE FUNCTION. A method binding cannot work:
     * the MIR function carries a receiver parameter with no C counterpart, and
     * `Sig::emitModule` exports only `$module->functions`, so a class-based
     * binding could never be imported across a `.o` boundary — which is the one
     * property that makes `Runtime\Libc\*` usable at all.
     */
    private function checkFfiOnClass(\Parser\Ast\ClassDecl $d): void
    {
        $cls = \ltrim($this->declName($d), '\\');
        foreach ($d->attributes as $a) {
            $kind = $this->ffiAttrKind($a);
            if ($kind === '') { continue; }
            $this->attrFail('#[Ffi\\' . $kind . '] on class ' . $cls
                . ': the Ffi attributes describe a free-function binding, not a class',
                $d->span);
        }
        foreach ($d->methods as $m) {
            foreach ($this->methodAttrs($m) as $a) {
                $kind = $this->ffiAttrKind($a);
                if ($kind === '') { continue; }
                $this->attrFail('#[Ffi\\' . $kind . '] on ' . $cls . '::'
                    . $this->methodDeclName($m) . '(): an FFI binding must be a free'
                    . ' function — group bindings with a namespace, not a class',
                    $this->methodSpan($m));
            }
        }
    }

    /** True when the PHP hint carries a raw address rather than a number. */
    private function ffiHintIsPointer(?string $hint): bool
    {
        return $this->ffiCType($hint) === 'ptr';
    }

    /** True when the hint is a PHP `string` — refcount-owned, never C's to free. */
    private function ffiHintIsPhpString(?string $hint): bool
    {
        if ($hint === null) { return false; }
        return \strtolower(\ltrim($hint, '?\\')) === 'string';
    }

    /** Ownership + placement rules for one function declaration. */
    private function checkFfiBinding(\Parser\Ast\FunctionDecl $d): void
    {
        $name = \ltrim($this->fnDeclName($d), '\\');
        $where = $name . '()';
        $span = $this->fnDeclSpan($d);
        $attrs = $this->fnDeclAttrs($d);
        $params = $this->fnDeclParams($d);

        $isBinding = false;
        $give = false;
        $staticPtr = false;
        foreach ($attrs as $a) {
            $kind = $this->ffiAttrKind($a);
            if ($kind === 'Symbol')    { $isBinding = true; }
            if ($kind === 'Give')      { $give = true; }
            if ($kind === 'StaticPtr') { $staticPtr = true; }
        }
        // O1 — an Ffi attribute anywhere on a declaration that is not a binding.
        if (!$isBinding) {
            foreach ($attrs as $a) {
                $kind = $this->ffiAttrKind($a);
                if ($kind === '') { continue; }
                $this->attrFail('#[Ffi\\' . $kind . '] on ' . $where
                    . ': the Ffi attributes describe an FFI binding, but ' . $where
                    . ' has no #[Ffi\\Symbol]', $span);
            }
            foreach ($params as $p) {
                foreach ($this->paramAttrs($p) as $a) {
                    $kind = $this->ffiAttrKind($a);
                    if ($kind === '') { continue; }
                    $this->attrFail('#[Ffi\\' . $kind . '] on parameter $' . $p->name
                        . ' of ' . $where . ': the Ffi attributes describe an FFI'
                        . ' binding, but ' . $where . ' has no #[Ffi\\Symbol]', $span);
                }
            }
            return;
        }

        $ret = $d->returnType;
        // O2 — the callee cannot both hand ownership over and keep it forever.
        if ($give && $staticPtr) {
            $this->attrFail('#[Ffi\\Give] and #[Ffi\\StaticPtr] are mutually exclusive on '
                . $where, $span);
        }
        // O5/O7 — a return-ownership claim needs something to own.
        if ($give || $staticPtr) {
            $claim = $give ? 'Give' : 'StaticPtr';
            if ($this->ffiHintIsPhpString($ret)) {
                $this->attrFail('#[Ffi\\' . $claim . '] on ' . $where
                    . ': a C-owned buffer has no refcount header — declare the return'
                    . ' \\Ffi\\Ptr, not string', $span);
            } elseif (!$this->ffiHintIsPointer($ret)) {
                $shown = $ret === null ? 'nothing' : \ltrim($ret, '\\');
                $this->attrFail('#[Ffi\\' . $claim . '] on ' . $where
                    . ': the binding returns ' . $shown . ', which carries no pointer',
                    $span);
            }
        }

        foreach ($params as $p) {
            $own = '';
            $dupe = false;
            foreach ($this->paramAttrs($p) as $a) {
                $kind = $this->ffiAttrKind($a);
                if ($kind !== 'Borrow' && $kind !== 'BorrowMut' && $kind !== 'Take') {
                    continue;
                }
                if ($own !== '') { $dupe = true; }
                $own = $kind;
            }
            if ($own === '') { continue; }
            $pWhere = 'parameter $' . $p->name . ' of ' . $where;
            // O3 — one parameter, one ownership story.
            if ($dupe) {
                $this->attrFail('#[Ffi\\Borrow], #[Ffi\\BorrowMut] and #[Ffi\\Take] are'
                    . ' mutually exclusive on ' . $pWhere, $this->paramSpan($p));
                continue;
            }
            // O6 — a PHP string is refcount-owned; C must never free it.
            if ($own === 'Take' && $this->ffiHintIsPhpString($p->typeHint)) {
                $this->attrFail('#[Ffi\\Take] on ' . $pWhere
                    . ': a PHP string is refcount-owned and must not be freed by C —'
                    . ' declare it \\Ffi\\Ptr', $this->paramSpan($p));
                continue;
            }
            // O4 — ownership is a property of a pointer, not of a number.
            if (!$this->ffiHintIsPointer($p->typeHint)) {
                $shown = $p->typeHint === null ? 'nothing' : \ltrim($p->typeHint, '\\');
                $this->attrFail('#[Ffi\\' . $own . '] on ' . $pWhere
                    . ': ownership applies to a pointer, but $' . $p->name
                    . ' is declared ' . $shown, $this->paramSpan($p));
            }
        }
    }

    /** Every attribute site a class declaration owns, plus the `#[Override]` checks. */
    private function checkClassDeclAttrs(\Parser\Ast\ClassDecl $d): void
    {
        $cls = \ltrim($this->declName($d), '\\');
        $this->checkAttrSite($d->attributes, \Compile\BuiltinAttributes::TARGET_CLASS, $d->span);
        $this->bakeAttrSiteErrors($d->attributes, \Compile\BuiltinAttributes::TARGET_CLASS,
            $cls, 'c', '');
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
            $this->bakeAttrSiteErrors($this->methodAttrs($m),
                \Compile\BuiltinAttributes::TARGET_METHOD, $cls, 'm', $this->methodDeclName($m));
            foreach ($this->methodDeclParams($m) as $p) {
                // A promoted constructor parameter is BOTH sites at once, so an
                // attribute there is valid when it targets EITHER — php attaches
                // it to whichever side accepts it. Checking the two targets in
                // two STRICT passes demanded that every attribute satisfy both,
                // so `#[\SensitiveParameter] private string $secret` — parameter-
                // only, and exactly what symfony/http-foundation's UriSigner
                // writes — passed the parameter pass and failed the property one.
                // php's message names the PARAMETER when neither matches.
                $this->checkAttrSite($this->paramAttrs($p),
                    \Compile\BuiltinAttributes::TARGET_PARAMETER, $this->paramSpan($p),
                    $this->paramPromoted($p) !== ''
                        ? \Compile\BuiltinAttributes::TARGET_PROPERTY : 0);
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
            $this->bakeAttrSiteErrors($this->propAttrs($p),
                \Compile\BuiltinAttributes::TARGET_PROPERTY, $cls, 'p', $this->propName($p));
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
     * `$alsoAllow` widens what is ACCEPTED without changing what the message
     * names — a promoted constructor parameter is a parameter and a property at
     * once, and php takes an attribute that targets either.
     *
     * @param \Parser\Ast\AttributeNode[] $attrs
     */
    private function checkAttrSite(array $attrs, int $target, \Parser\Ast\Span $span, int $alsoAllow = 0): void
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
            if (($flags & ($target | $alsoAllow)) === 0) {
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

    /**
     * Bake the newInstance() verdict for every attribute at one site.
     *
     * php checks a USERLAND attribute's target and repeatability only when the
     * instance is constructed, so the answer has to travel to runtime. Here is
     * the last point where the attribute class's own `#[Attribute(flags)]` is
     * readable — `ClassDef::$attributes` keeps names only.
     *
     * `$kind` / `$member` / the index must match ReflectSynth::attrFn's site key.
     *
     * @param \Parser\Ast\AttributeNode[] $attrs
     */
    private function bakeAttrSiteErrors(array $attrs, int $target, string $declClass, string $kind, string $member): void
    {
        $mod = $this->module;
        if ($mod === null) { return; }
        /** @var array<string,int> $counts */
        $counts = [];
        foreach ($attrs as $a) {
            $fqn = $this->attrFqn($a);
            $counts[$fqn] = ($counts[$fqn] ?? 0) + 1;
        }
        $k = -1;
        /** @var array<string,int> $seen */
        $seen = [];
        foreach ($attrs as $a) {
            $k = $k + 1;
            $fqn = $this->attrFqn($a);
            $repeat = isset($seen[$fqn]);
            $seen[$fqn] = 1;
            $err = $this->attrUseError($fqn, $target, $repeat);
            if ($err === '') { continue; }
            $mod->attrSiteErrors[$declClass . '|' . $kind . '|' . $member . '|' . (string)$k] = $err;
        }
    }

    /**
     * The \Error message php raises for one attribute use, or '' when the use is
     * valid (or cannot be judged — an attribute class declared elsewhere).
     */
    private function attrUseError(string $fqn, int $target, bool $repeat): string
    {
        $short = $fqn;
        $bs = \strrpos($fqn, '\\');
        if ($bs !== false) { $short = \substr($fqn, $bs + 1); }
        $flags = -1;
        if (\Compile\BuiltinAttributes::isReserved($fqn)) {
            $flags = \Compile\BuiltinAttributes::flagsOf($fqn);
        } else {
            $decl = $this->classDecls[$fqn] ?? null;
            if ($decl === null) { return ''; }
            $marker = null;
            foreach ($decl->attributes as $ma) {
                if ($this->attrFqn($ma) === 'Attribute') { $marker = $ma; }
            }
            if ($marker === null) {
                return 'Attempting to use non-attribute class "' . $short . '" as attribute';
            }
            $flags = $this->evalAttrFlags($marker);
            if ($flags < 0) { return ''; }
        }
        if (($flags & $target) === 0) {
            return 'Attribute "' . $short . '" cannot target '
                . \Compile\BuiltinAttributes::targetWord($target)
                . ' (allowed targets: ' . \Compile\BuiltinAttributes::allowedList($flags) . ')';
        }
        if ($repeat && ($flags & \Compile\BuiltinAttributes::IS_REPEATABLE) === 0) {
            return 'Attribute "' . $short . '" must not be repeated';
        }
        return '';
    }

    /**
     * The flag argument of a `#[Attribute(...)]` marker: an int literal, an
     * `Attribute::TARGET_*` constant, or any `|` of those. Bare `#[Attribute]`
     * means TARGET_ALL. -1 when the expression is something else, which makes
     * the site unjudged rather than wrongly rejected.
     */
    private function evalAttrFlags(\Parser\Ast\AttributeNode $marker): int
    {
        $args = $this->attrArgs($marker);
        if ($args === []) { return \Compile\BuiltinAttributes::TARGET_ALL; }
        $a0 = $args[0];
        if ($a0->kind === 'NamedArg') { $a0 = $this->namedArgValue($a0); }
        return $this->evalFlagExpr($a0);
    }

    private function evalFlagExpr(\Parser\Ast\Expr $e): int
    {
        if ($e->kind === 'IntLiteral') { return $this->intLitValue($e); }
        if ($e->kind === 'StaticAccess') {
            if (\ltrim($this->staticAccessClass($e), '\\') !== 'Attribute') { return -1; }
            $v = \Compile\BuiltinAttributes::constValue($this->staticAccessName($e));
            return $v === null ? -1 : $v;
        }
        if ($e->kind === 'BinaryOp' && $this->binaryOp($e) === '|') {
            $l = $this->evalFlagExpr($this->binaryLeft($e));
            $r = $this->evalFlagExpr($this->binaryRight($e));
            if ($l < 0 || $r < 0) { return -1; }
            return $l | $r;
        }
        return -1;
    }

    private function intLitValue(\Parser\Ast\IntLiteral $e): int { return $e->value; }
    private function unaryOpOf(\Parser\Ast\UnaryOp $e): string { return $e->op; }
    private function unaryOperandOf(\Parser\Ast\UnaryOp $e): \Parser\Ast\Expr { return $e->operand; }
    private function staticAccessClass(\Parser\Ast\StaticAccess $e): string { return $e->class; }
    private function staticAccessName(\Parser\Ast\StaticAccess $e): string { return $e->name; }
    private function binaryOp(\Parser\Ast\BinaryOp $e): string { return $e->op; }
    private function binaryLeft(\Parser\Ast\BinaryOp $e): \Parser\Ast\Expr { return $e->left; }
    private function binaryRight(\Parser\Ast\BinaryOp $e): \Parser\Ast\Expr { return $e->right; }

    /** Whether an AST expression is a `(void)` cast. */
    private function isVoidCastExpr(\Parser\Ast\Expr $e): bool
    {
        if ($e->kind !== 'Cast') { return false; }
        return \strtolower($this->castTarget($e)) === 'void';
    }

    private function castTarget(\Parser\Ast\Cast $c): string { return $c->cast; }

    /** Mark a lowered call as `(void)`-discarded (no-op for anything else). */
    private function markVoidCast(\Compile\Mir\Node $n): void
    {
        if ($n->kind === \Compile\Mir\Node::KIND_CALL) {
            $this->setCallVoidCast($n);
        } elseif ($n->kind === \Compile\Mir\Node::KIND_METHOD_CALL) {
            $this->setMethodCallVoidCast($n);
        } elseif ($n->kind === \Compile\Mir\Node::KIND_STATIC_CALL) {
            $this->setStaticCallVoidCast($n);
        }
    }

    private function setCallVoidCast(\Compile\Mir\Call $n): void { $n->voidCast = true; }
    private function setMethodCallVoidCast(\Compile\Mir\MethodCall_ $n): void { $n->voidCast = true; }
    private function setStaticCallVoidCast(\Compile\Mir\StaticCall_ $n): void { $n->voidCast = true; }

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
    private function methodDeclBodyIsNull(\Parser\Ast\MethodDecl $m): bool
    {
        // A deferred concrete body is semantically present. Do not materialize it
        // during declaration diagnostics: doing so would recreate every method
        // AST before the lowering loop and defeat lazy body ownership.
        return $m->body === null && $m->lazyBody === null;
    }
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
