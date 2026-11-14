<?php

namespace Compile\Mir\Passes;

use Compile\Mir\Add;
use Compile\Mir\BitOp;
use Compile\Mir\BitNot_;
use Compile\Mir\ArrayAccess_;
use Compile\Mir\ArrayElement_;
use Compile\Mir\ArrayLit;
use Compile\Mir\Spread_;
use Compile\Mir\Block;
use Compile\Mir\ClassDef;
use Compile\Mir\EnumDef;
use Compile\Mir\BoolConst;
use Compile\Mir\Break_;
use Compile\Mir\Call;
use Compile\Mir\Walk;
use Compile\Mir\Closure_;
use Compile\Mir\Invoke_;
use Compile\Mir\NullCoalesce_;
use Compile\Mir\Instanceof_;
use Compile\Mir\Cast;
use Compile\Mir\Cmp;
use Compile\Mir\Concat;
use Compile\Mir\Continue_;
use Compile\Mir\Goto_;
use Compile\Mir\Label_;
use Compile\Mir\Div;
use Compile\Mir\Echo_;
use Compile\Mir\FloatConst;
use Compile\Mir\FunctionDef;
use Compile\Mir\Foreach_;
use Compile\Mir\For_;
use Compile\Mir\DoWhile_;
use Compile\Mir\IncDec;
use Compile\Mir\StaticProp_;
use Compile\Mir\StoreStaticProp_;
use Compile\Mir\StaticLocalDecl_;
use Compile\Mir\Isset_;
use Compile\Mir\Unset_;
use Compile\Mir\ClassName_;
use Compile\Mir\RefAlias_;
use Compile\Mir\RefBind_;
use Compile\Mir\RefAddr_;
use Compile\Mir\Throw_;
use Compile\Mir\Yield_;
use Compile\Mir\TryCatch_;
use Compile\Mir\MirCatch;
use Compile\Mir\Ternary;
use Compile\Mir\Switch_;
use Compile\Mir\SwitchArm_;
use Compile\Mir\Match_;
use Compile\Mir\MatchArm_;
use Compile\Mir\If_;
use Compile\Mir\IntConst;
use Compile\Mir\LoadLocal;
use Compile\Mir\MethodCall_;
use Compile\Mir\Mod;
use Compile\Mir\Module;
use Compile\Mir\Mul;
use Compile\Mir\Neg;
use Compile\Mir\NewObj;
use Compile\Mir\Node;
use Compile\Mir\Not_;
use Compile\Mir\NullConst;
use Compile\Mir\Param;
use Compile\Mir\Pass;
use Compile\Mir\PropertyAccess_;
use Compile\Mir\Return_;
use Compile\Mir\StaticCall_;
use Compile\Mir\StoreElement;
use Compile\Mir\StoreLocal;
use Compile\Mir\StoreProperty;
use Compile\Mir\DynProp_;
use Compile\Mir\StoreDynProp_;
use Compile\Mir\StringConst;
use Compile\Mir\Sub;
use Compile\Mir\Type;
use Compile\Mir\While_;
use Parser\Ast\Program;

/**
 * Type hints and docblocks → MIR types. `lowerTypeHint` is the one funnel
 * every source or doc hint passes through.
 *
 * A trait on the one {@see LowerFromAst} host — split by concern so a reader opens
 * the file for the thing they are looking at. State stays on the host.
 */
trait LowerTypes
{
    /**
     * Param type from its effective hint. A FULLY untyped param (no hint, no
     * usable docblock → `$eff === null`) is `mixed` in PHP: a NaN-boxed cell so
     * `is_*` / dynamic dispatch / array access / casts / boolean tests on it
     * work at runtime (an `unknown` would flow as a raw i64 and mis-read the
     * tag). A bare `array` (or any present) hint is NOT this — it lowers via
     * {@see lowerTypeHint} (bare `array` → unknown, element recovered from use).
     */
    private function lowerParamType(?string $eff): Type
    {
        if ($eff === null) { return Type::cell(); }
        // A `@param T` is erased for the shared body — same as before generics,
        // where `T` simply looked like an unknown class name.
        return $this->lowerTypeHint($eff)->eraseTypeVars();
    }

    /** @var array<string, \Semantic\SourceType> parsed hints; a tree carries no class context */
    private array $typeHintTrees = [];

    /** @var array<string, bool> hints the parser rejects */
    private array $typeHintBad = [];

    /**
     * A hint string → its Type: parsed once per distinct string
     * ({@see \Semantic\SourceTypeParser}), lowered against the current pass
     * state (class, type params, substitution) every time. Unparseable → unknown.
     */
    private function lowerTypeHint(?string $hint): Type
    {
        if ($hint === null) { return Type::unknown(); }
        if (isset($this->typeHintTrees[$hint])) { return $this->lowerSource($this->typeHintTrees[$hint]); }
        if (isset($this->typeHintBad[$hint])) { return Type::unknown(); }
        $t = \Semantic\SourceTypeParser::fromHint($hint);
        if ($t === null) {
            $this->typeHintBad[$hint] = true;
            return Type::unknown();
        }
        $this->typeHintTrees[$hint] = $t;
        return $this->lowerSource($t);
    }

    /**
     * A parsed {@see \Semantic\SourceType} → Type under the current pass state. A class
     * type parameter or a bound trait parameter first; `A&B` types as its first arm.
     */
    private function lowerSource(\Semantic\SourceType $t): Type
    {
        $k = $t->kind;
        if ($k === \Semantic\SourceType::NULLABLE) { return $this->lowerSourceNullable($t); }
        if ($k === \Semantic\SourceType::CLASS_) {
            if (isset($this->currentTypeSubst[$t->name])) { return $this->currentTypeSubst[$t->name]; }
            if ($this->isTypeParam($t->name)) {
                return Type::typeVar($t->name, $this->currentTypeBounds[$t->name] ?? null);
            }
        }
        if ($k === \Semantic\SourceType::UNION) { return $this->lowerSourceUnion($t); }
        if ($k === \Semantic\SourceType::INTERSECTION) { return $this->lowerSource($t->args[0]); }
        return $this->lowerSourceCore($t, false);
    }

    /**
     * `?T` reads the core with `nullable` set. Spelled `T|null`, a container
     * (`string[]|null`, `array<K,V>|null`) or a parenthesised arm stays a cell: a
     * raw array pointer cannot carry the null. `callable(A): R|null` — the `|null`
     * belongs to the return.
     */
    private function lowerSourceNullable(\Semantic\SourceType $t): Type
    {
        $core = $t->args[0];
        if ($t->resolved === \Semantic\SourceType::UNION_NULL) {
            if ($this->isSourceCallableSig($core) && $core->resolved !== \Semantic\SourceType::PARENS) {
                return $this->lowerSourceCallable($core, true);
            }
            if ($this->isSourceArrayLike($core) || \str_contains($core->toString(), '(')) { return Type::cell(); }
        }
        return $this->lowerSourceCore($core, true);
    }

    /** A union with no single nullable arm: an all-array union, a numeric union, else a cell. */
    private function lowerSourceUnion(\Semantic\SourceType $t): Type
    {
        $arrays = true;
        $numeric = true;
        foreach ($t->args as $a) {
            if (!$this->isSourceArrayLike($a) || \str_contains($a->toString(), '|')) { $arrays = false; }
            if (!($a->kind === \Semantic\SourceType::SCALAR && ($a->name === 'int' || $a->name === 'float'))) { $numeric = false; }
        }
        if ($arrays) { return Type::vec(Type::unknown()); }
        if ($numeric) { return Type::numericCell(); }
        return Type::cell();
    }

    /** `X[]`, `array<…>`, `list<…>` and their non-empty spellings ({@see looksLikeArrayElemType}). */
    private function isSourceArrayLike(\Semantic\SourceType $t): bool
    {
        if ($t->kind !== \Semantic\SourceType::GENERIC) { return false; }
        $low = \strtolower(\ltrim($t->name, '\\'));
        return $low === 'array' || $low === 'list' || $low === 'non-empty-list' || $low === 'non-empty-array';
    }

    private function isSourceCallableSig(\Semantic\SourceType $t): bool
    {
        return $t->kind === \Semantic\SourceType::CALLABLE && ($t->args !== [] || $t->ret !== null);
    }

    /**
     * `callable(A): R` / `Closure(A): R`. Spelled `…: R|null`, the `|null`
     * belongs to the return. A bare `callable` / `Closure` (no signature) is the plain
     * closure type. A parameter written with a name, `&` or `...` erases.
     */
    private function lowerSourceCallable(\Semantic\SourceType $t, bool $retOrNull): Type
    {
        $params = [];
        // A parameter written with a name, `&` or `...` after its type reads as
        // one type token in lowerTypeArgs and erases (`int $x` is no type).
        foreach ($t->args as $i => $a) {
            $params[] = ($t->keys[$i] ?? '') !== '' ? Type::unknown() : $this->lowerSource($a);
        }
        $ret = null;
        $r = $t->ret;
        if ($r !== null) {
            if ($retOrNull && $r->kind !== \Semantic\SourceType::NULLABLE) {
                $r = new \Semantic\SourceType(\Semantic\SourceType::NULLABLE, '', [$r], \Semantic\SourceType::UNION_NULL);
            }
            $ret = $this->lowerSource($r);
        }
        return Type::closureOf($ret, $params);
    }

    /** A collection ELEMENT: a bare `callable` is a cell — a string or `[class, method]` pair is not an env pointer. */
    private function lowerSourceElem(\Semantic\SourceType $t): Type
    {
        if (($t->kind === \Semantic\SourceType::CALLABLE || $t->kind === \Semantic\SourceType::CLASS_)
            && $t->args === [] && $t->ret === null && \strtolower(\ltrim($t->name, '\\')) === 'callable') {
            return Type::cell();
        }
        return $this->lowerSource($t);
    }

    /** A non-union type; `$nullable` = written `?T` (a nullable scalar or enum boxes). */
    private function lowerSourceCore(\Semantic\SourceType $t, bool $nullable): Type
    {
        $k = $t->kind;
        if ($k === \Semantic\SourceType::INTERSECTION) { return $this->lowerSourceCore($t->args[0], $nullable); }
        if ($k === \Semantic\SourceType::CALLABLE && $this->isSourceCallableSig($t)) { return $this->lowerSourceCallable($t, false); }
        if ($k === \Semantic\SourceType::SHAPE) { return $this->lowerSourceShape($t); }
        if ($k === \Semantic\SourceType::LITERAL || $k === \Semantic\SourceType::CONST_REF) { return Type::unknown(); }
        if ($k === \Semantic\SourceType::UNION || $k === \Semantic\SourceType::NULLABLE) { return $this->lowerSource($t); }
        if ($k !== \Semantic\SourceType::GENERIC && !($k === \Semantic\SourceType::ITERABLE && $t->args !== [])) {
            return $this->lowerSourceName($t->name, $nullable);
        }
        $low = \strtolower(\ltrim($t->name, '\\'));
        $args = $t->args;
        $n = \count($args);
        if ($low === 'array' || $low === 'non-empty-array') {
            if ($n < 2) { return Type::vec($this->lowerSourceElem($args[0])); }
            // A class-constant key (`array<Foo::BAR, V>`) names VALUES, not a key type: a cell key.
            $kt = $this->lowerSource($args[0]);
            if (\str_contains($args[0]->toString(), '::')) { $kt = Type::cell(); }
            return Type::assoc($kt, $this->lowerSourceElem($args[1]));
        }
        if ($low === 'list' || $low === 'non-empty-list') {
            return Type::vec($n === 1 ? $this->lowerSourceElem($args[0]) : Type::unknown());
        }
        if ($low === 'int') { return Type::int_(); }
        if ($low === 'class-string') { return Type::string_(); }
        if ($low === 'generator') {
            if ($n === 1) { return Type::generator($this->lowerSource($args[0])); }
            $key = $this->lowerSource($args[0]);
            return Type::generator($n === 2 ? $this->lowerSource($args[1]) : Type::unknown(), $key);
        }
        $gname = $this->resolveClassHint(\ltrim($t->name, '\\'));
        if ($gname === '') { return Type::unknown(); }
        $gargs = [];
        foreach ($args as $a) { $gargs[] = $this->lowerSource($a); }
        $this->checkNbufBinding($gname, $gargs);
        return Type::objOf($gname, $gargs);
    }

    /** A bare type NAME: keywords and pseudo-types, then a `#[TypeDef]` carrier, then a class. */
    private function lowerSourceName(string $name, bool $nullable): Type
    {
        $low = \strtolower(\ltrim($name, '\\'));
        if ($low === 'mixed' || $low === 'iterable' || $low === 'object') { return Type::cell(); }
        if ($low === 'int' || $low === 'integer' || $low === 'positive-int' || $low === 'negative-int'
            || $low === 'non-negative-int' || $low === 'non-positive-int' || $low === 'non-zero-int') {
            return $nullable ? Type::numericCell() : Type::int_();
        }
        if ($low === 'float' || $low === 'double') { return $nullable ? Type::numericCell() : Type::float_(); }
        if ($low === 'bool' || $low === 'boolean') { return $nullable ? Type::numericCell() : Type::bool_(); }
        if ($low === 'string' || $low === 'non-empty-string' || $low === 'numeric-string' || $low === 'class-string'
            || $low === 'literal-string' || $low === 'lowercase-string' || $low === 'non-falsy-string'
            || $low === 'truthy-string') {
            return Type::string_();
        }
        if ($low === 'void') { return Type::void(); }
        if ($low === 'null') { return Type::null_(); }
        if ($low === 'resource') { return Type::obj('Resource'); }
        if ($low === 'ffi\\ptr') { return Type::obj('Ffi\\Ptr'); }
        if ($low === 'closure' || $low === 'callable') { return Type::closure(); }
        if (($low === 'self' || $low === 'static') && $this->currentLowerClass !== '') {
            return Type::obj($this->currentLowerClass);
        }
        if ($low === 'parent' && isset($this->classTable[$this->currentLowerClass])) {
            $pc = $this->classTable[$this->currentLowerClass]->parent;
            if ($pc !== '') { return Type::obj($pc); }
        }
        if ($low === 'generator') { return Type::generator(null); }
        $cls = \ltrim($name, '\\');
        if ($this->isTypeDef($cls)) { return $this->typeDefCarrier($cls); }
        $resolved = $this->resolveClassHint($cls);
        if ($resolved === '') { return Type::unknown(); }
        if ($resolved !== $cls && $this->isTypeDef($resolved)) { return $this->typeDefCarrier($resolved); }
        return $this->classHintType($resolved, $nullable);
    }

    /**
     * `array{k: T, k2?: U, V}` → a SHAPE: an int key is an int key, any other a string.
     * `k?:`, a `?T` or a `null` arm make the field NULL-able. Unsealed (`...`) or empty
     * lowers flat.
     */
    private function lowerSourceShape(\Semantic\SourceType $t): Type
    {
        $sealed = $t->resolved !== '...';
        if ($sealed && $t->args === []) { return Type::vec(Type::cell()); }
        $fields = [];
        $nullable = [];
        $list = true;
        foreach ($t->args as $i => $v) {
            $keyStr = $t->keys[$i];
            $opt = \str_ends_with($keyStr, \Semantic\SourceType::OPT);
            if ($opt) { $keyStr = \substr($keyStr, 0, \strlen($keyStr) - 1); }
            if (Type::isIntKey($keyStr)) {
                $key = (int)$keyStr;
            } else {
                $key = $keyStr;
                $list = false;
            }
            $sk = Type::shapeKey($key);
            $fields[$sk] = $this->lowerSource($v);
            if ($opt || $v->kind === \Semantic\SourceType::NULLABLE
                || ($v->kind === \Semantic\SourceType::SCALAR && $v->name === 'null')) {
                $nullable[$sk] = true;
            }
            if ($v->kind === \Semantic\SourceType::UNION) {
                foreach ($v->args as $arm) {
                    if ($arm->kind === \Semantic\SourceType::SCALAR && $arm->name === 'null') { $nullable[$sk] = true; }
                }
            }
        }
        if (!$sealed || \count($fields) === 0) {
            return $list ? Type::vec(Type::cell()) : Type::assoc(Type::string_(), Type::cell());
        }
        return Type::shapeOf($fields, $nullable, true);
    }

    /**
     * The declared class a hint NAME refers to, '' when none: the name as
     * written, else the nearest enclosing namespace that declares it, else the
     * one class in the module with that short name.
     */
    private function resolveClassHint(string $cls): string
    {
        if (isset($this->classTable[$cls]) || isset($this->knownClassNames[$cls])) {
            return $cls;
        }
        // Unqualified short name: PHP resolves it in the current namespace
        // first. Prefer the same-namespace class — this disambiguates a
        // short name shared across namespaces (`FunctionDef` in both
        // `Codegen\Llvm` and `Compile\Mir`) that the global heuristic
        // below would otherwise mark ambiguous and erase.
        if (\strpos($cls, '\\') === false && $this->currentDeclNamespace !== '') {
            // Walk the declaring namespace and its ANCESTORS: a pass in
            // `Compile\Mir\Passes` naming `Type` means `Compile\Mir\Type` (a
            // sibling of its own package), resolved by a file `use`. Doc-comment
            // generic inners (`@var array<string, Type>`) carry the raw short
            // name with no `use` context, and the global short→FQN map marks
            // `Type` ambiguous (also `Codegen\Llvm\Type`). The nearest enclosing
            // namespace that declares it is the PHP-correct pick and disambiguates
            // without per-file alias tracking (which the merged module drops).
            $ns = $this->currentDeclNamespace;
            while ($ns !== '') {
                $qualified = $ns . '\\' . $cls;
                if (isset($this->classTable[$qualified]) || isset($this->knownClassNames[$qualified])) {
                    return $qualified;
                }
                $p = \strrpos($ns, '\\');
                if ($p === false || $p < 0) { break; }
                $ns = \substr($ns, 0, $p);
            }
        }
        // Unqualified short name of a namespaced class (`Stmt` →
        // `Parser\Ast\Stmt`) — resolve when exactly one class declares it.
        if (\strpos($cls, '\\') === false
            && isset($this->shortClassFqn[$cls])
            && !isset($this->shortClassAmbiguous[$cls])) {
            return $this->shortClassFqn[$cls];
        }
        return '';
    }

    /**
     * The type a resolved class NAME lowers to — `obj<Cls>`, except that a
     * NULLABLE enum is a cell.
     *
     * `?Enum` is not a nullable pointer. An enum case is an ORDINAL, and the
     * first case's ordinal is 0 — exactly the carrier a null object uses — so
     * `$m === null` answered TRUE for the first case, and an actual null
     * reaching the cell→ordinal unbox dereferenced the NULL tag's bits and
     * SIGSEGV'd (`Method::tryFrom($unknown)` handed to a `?Method` parameter).
     * The union spelling `Enum|null` already lowers to a cell and is correct on
     * every one of those; `?Enum` now means the same thing, which is what php
     * says it means.
     */
    private function classHintType(string $cls, bool $nullable): Type
    {
        if ($nullable && isset($this->enumNames[\ltrim($cls, '\\')])) {
            return Type::cell();
        }
        return Type::obj($cls);
    }

    /**
     * Whether a declared `$hint` admits no object at all: every atom of it is an
     * array spelling (`array`, `array<…>`, `list<…>`, `T[]`, `array{…}`), a
     * scalar or null. `iterable`, `mixed`, `object` and any class name do not.
     */
    private function hintNeverObject(?string $hint): bool
    {
        if ($hint === null || $hint === '') { return false; }
        $h = \strtolower(\trim($hint));
        if ($h[0] === '?') { $h = \substr($h, 1); }
        $atoms = [];
        $depth = 0;
        $cur = '';
        $n = \strlen($h);
        for ($i = 0; $i < $n; $i++) {
            $c = $h[$i];
            if ($c === '<' || $c === '{' || $c === '(') { $depth = $depth + 1; }
            if ($c === '>' || $c === '}' || $c === ')') { $depth = $depth - 1; }
            if ($c === '|' && $depth === 0) { $atoms[] = \trim($cur); $cur = ''; continue; }
            $cur .= $c;
        }
        $atoms[] = \trim($cur);
        foreach ($atoms as $a) {
            if ($a === 'array' || \str_starts_with($a, 'array<') || \str_starts_with($a, 'array{')
                || \str_starts_with($a, 'list<') || \str_ends_with($a, '[]')) { continue; }
            if (\in_array($a, ['null', 'int', 'float', 'string', 'bool', 'false', 'true'], true)) { continue; }
            return false;
        }
        return true;
    }

    /** Whether `$hint` denotes a bare `array` with no element type. */
    private function isBareArrayHint(?string $hint): bool
    {
        if ($hint === null) { return false; }
        // `array|null` is `?array` spelled out: it erases the same way.
        $low = \strtolower(\str_replace(' ', '', \ltrim($hint, '?\\')));
        return $low === 'array' || $low === 'array|null' || $low === 'null|array';
    }

    /** A RETURN hint the +1 erased-array convention covers
     *  ({@see \Compile\Mir\Ownership::erasedArrayReturn}): a bare `array`,
     *  nullable (`?array`, `array|null`) or not. */
    private function isBareArrayReturnHint(?string $hint): bool
    {
        if ($hint === null) { return false; }
        $low = \strtolower(\str_replace(' ', '', \ltrim($hint, '?\\')));
        return $low === 'array' || $low === 'array|null' || $low === 'null|array';
    }

    /**
     * Whether `$hint` is an ELEMENT-ONLY array doc form — `T[]` or `array<V>`
     * with a single type argument.
     *
     * Both spell "an array of V" and say NOTHING about the keys: php has no
     * packed-vs-hashed distinction in a type expression at all, and `array<K, V>`
     * is the spelling that does commit a key type (which {@see lowerTypeHint}
     * honours). We lower the element-only forms to a packed vec anyway, because
     * that is what they nearly always describe and the packed path is the fast
     * one — but the key commitment is the compiler's, not the program's, so it
     * must be revisable when a call site proves otherwise ({@see Param::$docList}).
     */
    private function isElemOnlyArrayDoc(?string $hint): bool
    {
        if ($hint === null) { return false; }
        $base = \ltrim($hint, '?\\');
        $low = \strtolower($base);
        if (\strlen($base) > 2 && \substr($base, \strlen($base) - 2) === '[]') { return true; }
        if (\strncmp($low, 'array<', 6) !== 0) { return false; }
        $lt = \strpos($base, '<');
        $inner = \substr($base, $lt + 1, \strlen($base) - $lt - 2);
        // self-host strpos returns -1 (not false) on a miss; guard both, as the
        // `array<…>` lowering above does.
        $comma = \strpos($inner, ',');
        return $comma === false || $comma < 0;
    }

    /**
     * Recover a bare-`array` property's element type from how the class's own
     * methods STORE into it — the usage-inference fallback when neither a `@var`
     * docblock nor a list-literal default carried the element. A property has no
     * call site to refine its element, so an unknown element leaves every read
     * raw (an object field lands on a wrong offset, a string echoes as its
     * pointer). CONSERVATIVE: only push stores `$this->$prop[] = <resolvable>`
     * count; a keyed store, a wholesale non-empty reassign, or an unresolvable
     * value bails to null (stay erased). Every counted store must agree on one
     * concrete element type. Keeps the concrete fast path (no cell boxing).
     */
    private function inferPropElemFromStores(\Parser\Ast\ClassDecl $decl, string $prop): ?Type
    {
        // `$found` is seeded ONLY inside the guard below; a `?Type $found = null`
        // that is later read via `$found === null` / `$found->kind` is a nullable
        // object local whose null representation is unreliable under the native
        // self-build (a null-init obj slot compared `=== null` gave BOTH arms) —
        // gate on an explicit bool instead of the null sentinel.
        $found = Type::unknown();
        $have = false;
        foreach ($decl->methods as $m) {
            if ($m->body === null) { continue; }
            $paramTypes = [];
            foreach ($m->params as $p) {
                if ($p->typeHint !== null) {
                    $paramTypes[$p->name] = $this->lowerTypeHint($p->typeHint);
                }
            }
            $types = $this->scanStorePropTypes($m->body->statements, $prop, $paramTypes);
            foreach ($types as $t) {
                // An unknown entry marks an unresolvable / keyed / wholesale store.
                if ($t->kind === Type::KIND_UNKNOWN) { return null; }
                if (!$have) { $found = $t; $have = true; }
                elseif (!$this->sameElemType($found, $t)) { return null; }
            }
        }
        return $have ? $found : null;
    }

    /**
     * Element types stored to `$this->$prop` across a statement list (recurses
     * into if/loop/try/switch bodies so a conflicting store is never missed). A
     * `Type::unknown()` entry signals "bail" to the caller.
     *
     * @param \Parser\Ast\Stmt[]     $stmts
     * @param array<string, Type>    $paramTypes
     * @return Type[]
     */
    private function scanStorePropTypes(array $stmts, string $prop, array $paramTypes): array
    {
        $out = [];
        foreach ($stmts as $s) {
            $k = $s->kind;
            if ($k === 'Expression') {
                $t = $this->storeElemTypeOf($s->expr, $prop, $paramTypes);
                if ($t !== null) { $out[] = $t; }
            } elseif ($k === 'If') {
                $if = $s;
                $out = \array_merge($out, $this->scanStorePropTypes($if->then->statements, $prop, $paramTypes));
                foreach ($if->elseifs as $ei) {
                    $out = \array_merge($out, $this->scanStorePropTypes($ei->body->statements, $prop, $paramTypes));
                }
                $else = $if->else;
                if ($else !== null) {
                    $out = \array_merge($out, $this->scanStorePropTypes($else->statements, $prop, $paramTypes));
                }
            } elseif ($k === 'While') {
                $out = \array_merge($out, $this->scanStorePropTypes($s->body->statements, $prop, $paramTypes));
            } elseif ($k === 'DoWhile') {
                $out = \array_merge($out, $this->scanStorePropTypes($s->body->statements, $prop, $paramTypes));
            } elseif ($k === 'For') {
                $out = \array_merge($out, $this->scanStorePropTypes($s->body->statements, $prop, $paramTypes));
            } elseif ($k === 'Foreach') {
                $out = \array_merge($out, $this->scanStorePropTypes($s->body->statements, $prop, $paramTypes));
            } elseif ($k === 'TryCatch') {
                $tc = $s;
                $out = \array_merge($out, $this->scanStorePropTypes($tc->try->statements, $prop, $paramTypes));
                foreach ($tc->catches as $c) {
                    $out = \array_merge($out, $this->scanStorePropTypes($c->body->statements, $prop, $paramTypes));
                }
                $fin = $tc->finally;
                if ($fin !== null) {
                    $out = \array_merge($out, $this->scanStorePropTypes($fin->statements, $prop, $paramTypes));
                }
            } elseif ($k === 'Switch') {
                foreach ($s->cases as $case) {
                    $out = \array_merge($out, $this->scanStorePropTypes($case->body, $prop, $paramTypes));
                }
            }
        }
        return $out;
    }

    /**
     * The element type of an `$this->$prop[] = X` push store, or null when the
     * expression is not a store to `$prop`. Returns `Type::unknown()` (bail) for
     * a keyed element store, a wholesale non-empty `$this->$prop = X` reassign,
     * or an unresolvable pushed value.
     *
     * @param array<string, Type> $paramTypes
     */
    private function storeElemTypeOf(\Parser\Ast\Expr $e, string $prop, array $paramTypes): ?Type
    {
        if ($e->kind !== 'Assign') { return null; }
        $as = $e;
        $target = $as->target;
        if ($target->kind === 'ArrayAccess') {
            $aa = $target;
            if (!$this->isThisProp($aa->array, $prop)) { return null; }
            if ($aa->index !== null) {
                // A string-keyed store implies an ASSOC; keep a resolvable value
                // type so untyped reads aren't erased to raw pointers (the bare
                // `array` assoc-value bug). An int / dynamic key can't be assumed
                // packed → bail (stay erased).
                if (!$this->syntacticKeyIsString($aa->index, $paramTypes)) { return Type::unknown(); }
                $this->propStoreStrKey = true;
                return $this->syntacticValueType($as->value, $paramTypes);
            }
            return $this->syntacticValueType($as->value, $paramTypes);
        }
        if ($this->isThisProp($target, $prop)) {
            // `$this->prop = []` re-init is fine (no element info); any other
            // wholesale assignment could seed a foreign element type → bail.
            $rhs = $as->value;
            if ($rhs->kind === 'ArrayLit') {
                if ($rhs->elements === []) { return null; }
                // A homogeneous list literal reveals the element type
                // (`$this->items = [5,6,7]` in the ctor → vec[int]), same as an
                // inline default — so a read / by-ref of `$this->items[$i]` stays
                // typed instead of erased to raw pointers.
                $elem = $this->inferBareArrayPropElem($rhs);
                if ($elem !== null) { return $elem; }
                // A string-keyed homogeneous literal (`$this->map = ["x"=>10]`) →
                // assoc[string, V]; flag it so buildClassDef builds an assoc type.
                $ve = $this->inferBareArrayPropAssocElem($rhs);
                if ($ve !== null) { $this->propStoreStrKey = true; return $ve; }
            }
            return Type::unknown();
        }
        return null;
    }

    /**
     * {@see effectiveHint} for a RETURN. An unhinted function's body already
     * says what it returns (NarrowReturns), so a docblock naming only scalars
     * is not allowed to override it: Laravel's `level()` is documented
     * `@return int` and returns a `Monolog\Level`, which the signature then
     * typed `int` and handed the enum's singleton pointer to every caller as a
     * number. Class, array and type-parameter docs still carry what the body
     * cannot show.
     */
    private function effectiveReturnHint(?string $hint, ?string $docType): ?string
    {
        if ($hint === null && $docType !== null
            && \preg_match('/^\??(?:int|integer|float|double|string|bool|boolean|null|false|true)(?:\|(?:int|integer|float|double|string|bool|boolean|null|false|true))*$/i', $docType) === 1) {
            return null;
        }
        return $this->effectiveHint($hint, $docType);
    }


    /**
     * Lower a type hint, recovering a bare `array`'s element type from a
     * docblock annotation token (`X[]` or `array<...>`). PHP's `array`
     * hint erases the element type; the `@param`/`@return`/`@var X[]`
     * docblock is the only carrier of it for object collections, and
     * without it `vec[obj]` reads collapse to the fallback offset. A
     * scalar doc token (no `[]` / `array<`) is ignored.
     */
    private function effectiveHint(?string $hint, ?string $docType): ?string
    {
        if ($this->isBareArrayHint($hint)
            && $docType !== null && $docType !== ''
            && ($this->looksLikeArrayElemType($docType) || $this->looksLikeArrayShapeType($docType))) {
            return $docType;
        }
        // No source hint at all → the docblock IS the type. This is what every
        // reader (and PHPStan) already assumes, and it was NOT honoured: a
        // `/** @param Dog $x */ public function add($x)` typed the param `cell`,
        // so the caller NaN-boxed the object — while `@var Dog[]` on the property
        // it gets stored into WAS honoured (a raw obj array). A boxed cell landed
        // in an object array and the next read took offset 16 of a tag → SIGSEGV.
        // A type parameter (`@param T`) is the same case: `T` cannot be written in
        // PHP syntax at all, so the docblock is its only source.
        // A docblock naming nothing this program declares (a `@phpstan-type`
        // alias, `non-empty-string`, a missing class) is NOT that type: it
        // lowered to `unknown`, an erased raw word, where the missing hint means
        // `mixed` — so a caller passed a string raw, and `is_string($x)` in the
        // body could not tell it from an array (php-cs-fixer's
        // `@param _PhpTokenPrototype $token` on Token::__construct).
        if ($hint === null && $docType !== null && $docType !== '') {
            // `resource` is documentation only, and what an untyped slot holds in
            // its place is routinely `false` (a failed fopen): an obj<Resource>
            // slot took that cell's bits as a pointer and `$this->os ?? …` crashed.
            if (\preg_match('/(^|[|?<,\s])resource($|[|>,\s\[])/i', $docType) === 1) { return null; }
            // `@param callable $cb` is a union — a function-name string, a
            // `[class, method]` pair or a Closure — and a docblock converts
            // nothing at the call: typed `closure`, WordPress's add_filter() took
            // a hook's string callback as an env pointer.
            if (\preg_match('/(^|[|?\s])callable($|[|\s])/i', \trim($docType)) === 1) { return null; }
            if ($this->lowerTypeHint($docType)->kind === Type::KIND_UNKNOWN
                && !$this->isBareArrayHint($docType)
                && !$this->looksLikeArrayElemType($docType)
                && !$this->looksLikeArrayShapeType($docType)) {
                return null;
            }
            return $docType;
        }
        // `Box $b` + `@param Box<float> $b` — the SAME class, and the docblock
        // additionally carries the binding. PHP has no syntax for the arguments,
        // so this is the only way to write them next to a real hint, and taking
        // the bare hint threw the binding away: `T` erased to a plain (non-numeric)
        // cell, and `$b->get(0) + $b->get(1)` on a Box<float> then took the INTEGER
        // arithmetic path and printed 0. The docblock does not widen the type here,
        // it refines it — prefer it.
        if ($hint !== null && $docType !== null && $this->bindsSameClass($hint, $docType)) {
            return $docType;
        }
        // A real hint next to a type-parameter docblock (`pop(): mixed` +
        // `@return T`, `offsetGet(): int` + `@return T`). PHP cannot write `T`,
        // so the hint is only the widest thing the author could say, and the
        // docblock refines it — when both lower to the same representation:
        // `mixed` holds any `T`; a scalar hint holds a `T` bound to that scalar
        // or to a `#[TypeDef]` over it. An unbound `T` (a cell) never replaces
        // a raw `int`. Taking the hint alone gave the specialization an
        // untyped return, which the narrowing pass then re-derived from the
        // body as the bare carrier — the value came out without its type.
        // (Inside a specialization the parameter is no longer one — it is a key
        // of the substitution the members are lowered under.)
        if ($hint !== null && $docType !== null
            && ($this->isTypeParam($docType) || isset($this->currentTypeSubst[$docType]))) {
            if (\strtolower(\ltrim($hint, '\\')) === 'mixed') { return $docType; }
            $dt = $this->lowerTypeHint($docType);
            if ($dt->kind !== Type::KIND_UNKNOWN && $dt->kind !== Type::KIND_CELL
                && $dt->kind === $this->lowerTypeHint($hint)->kind) {
                return $docType;
            }
        }
        return $hint;
    }

    /** Whether `$docType` is `$hint` with generic arguments spelled out —
     *  `Box` vs `Box<float>`, modulo a leading `?` / `\`. */
    private function bindsSameClass(string $hint, string $docType): bool
    {
        $lt = \strpos($docType, '<');
        if ($lt === false || $lt <= 0) { return false; }
        $base = \ltrim(\substr($docType, 0, $lt), '?\\');
        // A pseudo-type's arguments bind no class: `iterable $o` + `@param
        // iterable<Opt> $o` lowered the doc form, which is `unknown`, in place
        // of the hint's `cell` — and the `$o = iterator_to_array($o)` arm then
        // stored a cell into a slot read raw (php-cs-fixer FixerOptionSorter).
        $low = \strtolower($base);
        if ($low === 'iterable' || $low === 'callable' || $low === 'object' || $low === 'mixed') { return false; }
        // `int $n` + `@var int<0, max>` is a RANGE, not a class binding: taken,
        // it lowered the property to `unknown` and a cell stored into it stayed
        // tagged (sebastian/diff's `$contextLines`).
        if ($low === 'int' || $low === 'integer' || $low === 'float' || $low === 'string'
            || $low === 'bool' || $low === 'array') { return false; }
        if ($base === '') { return false; }
        $h = \ltrim($hint, '?\\');
        if ($base === $h) { return true; }
        // The same class under two spellings: the hint arrives resolved
        // (`Manticore\Ds\UInt16Array`), the docblock as written (`UInt16Array`).
        $rb = $this->resolveClassHint($base);
        return $rb !== '' && $rb === $this->resolveClassHint($h);
    }

    /** Whether `$t` is a `@template` parameter of the class being lowered (`T`, `T[]`). */
    private function mentionsTypeParam(string $t): bool
    {
        foreach ($this->currentTypeParams as $p) {
            if ($t === $p) { return true; }
            if ($t === $p . '[]') { return true; }
        }
        return false;
    }

    /** Whether `$t` is a container shape (`X[]` or `array<...>`). */
    private function looksLikeArrayElemType(string $t): bool
    {
        $n = \strlen($t);
        if ($n > 2 && \substr($t, $n - 2) === '[]') { return true; }
        $low = \strtolower(\ltrim($t, '?\\'));
        return \strncmp($low, 'array<', 6) === 0 || \strncmp($low, 'list<', 5) === 0
            || \strncmp($low, 'non-empty-list<', 15) === 0 || \strncmp($low, 'non-empty-array<', 16) === 0;
    }

    /** True for a docblock array SHAPE (`array{…}`, `list{…}`,
     *  `non-empty-array{…}`, `non-empty-list{…}`) — kept separate from
     *  {@see looksLikeArrayElemType} so its two other callers (the nullable-
     *  union collapse, the elem-type union check) keep their existing
     *  behaviour; only {@see effectiveHint} needs a shape docblock to win
     *  over a bare `array` native hint. */
    private function looksLikeArrayShapeType(string $t): bool
    {
        $low = \strtolower(\ltrim($t, '?\\'));
        return \strncmp($low, 'array{', 6) === 0 || \strncmp($low, 'list{', 5) === 0
            || \strncmp($low, 'non-empty-array{', 16) === 0
            || \strncmp($low, 'non-empty-list{', 15) === 0;
    }

    /**
     * Extract the type token for a docblock `@param X[] $name` (when
     * `$varName` is non-empty) or `@return X[]` / `@var X[]` (empty
     * `$varName`). Returns the raw token (`PropertyDecl[]`) or null.
     */
    /**
     * The substitution every member of `$decl` lowers under: what its generic
     * TRAITS are bound to, plus — for a reified class — what its OWN type
     * parameters are bound to. Both are copies of a body, so in both the binding
     * substitutes at the source and no type variable survives.
     *
     * @return array<string, Type> type-param name → the type it is bound to
     */
    private function memberTypeSubst(\Parser\Ast\ClassDecl $decl): array
    {
        $out = $this->traitTypeSubst($decl);
        foreach ($this->reifySubst[$decl->name] ?? [] as $tp => $t) { $out[$tp] = $t; }
        return $out;
    }

    /**
     * What a class binds its generic traits to — `/** @use Items<string> *\/ use Items;`.
     *
     * @return array<string, Type> trait type-param name → the type it is bound to
     */
    private function traitTypeSubst(\Parser\Ast\ClassDecl $decl): array
    {
        $out = [];
        foreach ($decl->uses as $traitName) {
            $tn = \ltrim($traitName, '\\');
            if (!isset($this->traitTable[$tn])) { continue; }
            $td = $this->traitTable[$tn];
            if (!isset($decl->useDocs[$tn])) { continue; }
            $hint = $this->docTagType($decl->useDocs[$tn], '@use', '');
            if ($hint === null || $hint === '') { continue; }
            $lt = \strpos($hint, '<');
            if ($lt === false || $lt <= 0) { continue; }
            $inner = \substr($hint, $lt + 1, \strlen($hint) - $lt - 2);
            $args = $this->lowerTypeArgs($inner);
            // docTemplates also fills the pending bound/default maps — keep them.
            $savedB = $this->pendingTypeBounds;
            $savedD = $this->pendingTypeDefaults;
            $params = $this->docTemplates($td->docComment);
            $this->pendingTypeBounds = $savedB;
            $this->pendingTypeDefaults = $savedD;
            $i = 0;
            foreach ($params as $p) {
                if ($i >= \count($args)) { break; }
                $out[$p] = $args[$i];
                $i = $i + 1;
            }
        }
        return $out;
    }

    /** Whether `$hint` names a `@template` parameter of the class being lowered. */
    private function isTypeParam(string $hint): bool
    {
        foreach ($this->currentTypeParams as $p) {
            if ($p === $hint) { return true; }
        }
        return false;
    }

    /**
     * Split the inside of a `Cls<…>` on top-level commas and lower each arg.
     * Depth-aware, so a nested `Map<string, Box<T>>` keeps its inner comma.
     *
     * @return Type[]
     */
    private function lowerTypeArgs(string $inner): array
    {
        $out = [];
        $n = \strlen($inner);
        $depth = 0;
        $start = 0;
        $i = 0;
        while ($i < $n) {
            $c = \substr($inner, $i, 1);
            if ($c === '<') { $depth = $depth + 1; }
            elseif ($c === '>') { if ($depth > 0) { $depth = $depth - 1; } }
            elseif ($c === ',' && $depth === 0) {
                $out[] = $this->lowerTypeHint(\trim(\substr($inner, $start, $i - $start)));
                $start = $i + 1;
            }
            $i = $i + 1;
        }
        $last = \trim(\substr($inner, $start, $n - $start));
        if ($last !== '') { $out[] = $this->lowerTypeHint($last); }
        return $out;
    }

    /**
     * The `@template T` names a class docblock declares, in order.
     *
     * Scans forward once with bounded `substr` (never `ltrim`/`rtrim` — the
     * self-host trim helpers can hand back a buffer whose later `substr` reads
     * garbage). `@template-covariant` and friends are skipped: the tag must be
     * followed by whitespace.
     *
     * @return string[]
     */
    private function docTemplates(?string $doc): array
    {
        $out = [];
        if ($doc === null) { return $out; }
        $n = \strlen($doc);
        $tag = '@template';
        $tlen = \strlen($tag);
        $i = 0;
        while ($i + $tlen <= $n) {
            if (\substr($doc, $i, $tlen) !== $tag) { $i = $i + 1; continue; }
            $j = $i + $tlen;
            $b = ($j < $n) ? \substr($doc, $j, 1) : '';
            if ($b !== ' ' && $b !== "\t") { $i = $i + 1; continue; }
            while ($j < $n) {
                $c = \substr($doc, $j, 1);
                if ($c !== ' ' && $c !== "\t") { break; }
                $j = $j + 1;
            }
            $start = $j;
            while ($j < $n) {
                $c = \substr($doc, $j, 1);
                if ($c === ' ' || $c === "\t" || $c === "\n" || $c === "\r") { break; }
                $j = $j + 1;
            }
            $name = \substr($doc, $start, $j - $start);
            if ($name !== '') {
                $out[] = $name;
                // `@template T of Animal` / `@template T = int` — an upper bound or
                // a default, on the same line. A bound is not a mere check here: a
                // bounded T erases to the BOUND (a raw pointer) rather than a cell.
                $j = $this->skipDocSpaces($doc, $j, $n);
                $kw = $this->readDocWord($doc, $j, $n);
                if ($kw === 'of' || $kw === '=') {
                    $j = $this->skipDocSpaces($doc, $j + \strlen($kw), $n);
                    $k = $j;
                    $j = $this->readDocTypeEnd($doc, $j, $n);
                    $hint = \substr($doc, $k, $j - $k);
                    if ($hint !== '') {
                        if ($kw === 'of') { $this->pendingTypeBounds[$name] = $hint; }
                        else { $this->pendingTypeDefaults[$name] = $hint; }
                    }
                }
            }
            $i = $j;
        }
        return $out;
    }

    /** Advance past spaces/tabs. */
    private function skipDocSpaces(string $doc, int $j, int $n): int
    {
        while ($j < $n) {
            $c = \substr($doc, $j, 1);
            if ($c !== ' ' && $c !== "\t") { break; }
            $j = $j + 1;
        }
        return $j;
    }

    /** The word at `$j` (empty at a line end). */
    private function readDocWord(string $doc, int $j, int $n): string
    {
        $k = $j;
        while ($k < $n) {
            $c = \substr($doc, $k, 1);
            if ($c === ' ' || $c === "\t" || $c === "\n" || $c === "\r") { break; }
            $k = $k + 1;
        }
        return \substr($doc, $j, $k - $j);
    }

    /** End of a type token, keeping `<…>` together (`array<string, int>`). */
    private function readDocTypeEnd(string $doc, int $j, int $n): int
    {
        $depth = 0;
        while ($j < $n) {
            $c = \substr($doc, $j, 1);
            if ($c === '<') { $depth = $depth + 1; }
            elseif ($c === '>') { if ($depth > 0) { $depth = $depth - 1; } }
            elseif ($depth === 0
                && ($c === ' ' || $c === "\t" || $c === "\n" || $c === "\r")) {
                break;
            }
            $j = $j + 1;
        }
        return $j;
    }

    /**
     * A FUNCTION's own `@template T` (`@param T $new` / `@return T`) is not a
     * class type parameter: nothing binds it per call, so it names the value's
     * type only as "whatever came in". Spelled literally, `T` resolved as a
     * class nobody declares — an erased RAW word, and a string default that
     * crossed into a `mixed` slot was boxed as an INT (php-cs-fixer's
     * `Future::getV4OrV3('always_last', 'always_first')`). It lowers to its
     * bound (`of X`), else `mixed`. Class templates live in the class
     * docblock, which never reaches here through these tags.
     */
    private function docTagType(?string $doc, string $tag, string $varName, bool $unnamedOnly = false): ?string
    {
        $t = \Analyze\DocType::tag($doc, $tag, $varName, $unnamedOnly);
        if ($t === null || $doc === null) { return $t; }
        if ($tag !== '@param' && $tag !== '@param-out' && $tag !== '@return') { return $t; }
        if (!\str_contains($doc, '@template')) { return $t; }
        $m = [];
        if (\preg_match_all('/@template\s+([A-Za-z_][A-Za-z0-9_]*)(?:\s+of\s+([^\s*]+))?/', $doc, $m) < 1) { return $t; }
        foreach ($m[1] as $i => $name) {
            $bound = $m[2][$i] ?? '';
            $sub = $bound !== '' ? $bound : 'mixed';
            $t = \preg_replace('/(?<![A-Za-z0-9_\\\\$])' . $name . '(?![A-Za-z0-9_\\\\])/', $sub, $t) ?? $t;
        }
        return $t;
    }

    /**
     * Record on `$fn` the function's own `@template T` bound by a `class-string<T>` param
     * ({@see FunctionDef::$tplClassArg}, {@see classTemplateCode}).
     * @param \Parser\Ast\Param[] $params
     */
    private function recordClassTemplate(FunctionDef $fn, ?string $doc, array $params): void
    {
        $code = $this->classTemplateCode($doc, $params);
        if ($code < 0) { return; }
        $fn->tplClassArg = \intdiv($code, 2);
        $fn->tplNullable = $code % 2 === 1;
    }

    /**
     * A doc's own `@template T` bound by a `class-string<T>` param with `@return` `T` / `?T` /
     * `T|null`: `2 * <source param index> + <1 when nullable>`, -1 for none. `@phpstan-` /
     * `@psalm-` spellings win. Containers of T (`T[]`, `list<T>`) do not count.
     * @param \Parser\Ast\Param[] $params
     */
    private function classTemplateCode(?string $doc, array $params): int
    {
        if ($doc === null || !\str_contains($doc, 'template')) { return -1; }
        $ret = \Semantic\DocNames::rawTag($doc, '@return', '');
        if ($ret === null) { return -1; }
        $ret = \str_replace(' ', '', $ret);
        $nullable = 1;
        if (\str_starts_with($ret, '?')) { $ret = \substr($ret, 1); }
        elseif (\str_starts_with($ret, 'null|')) { $ret = \substr($ret, 5); }
        elseif (\str_ends_with($ret, '|null')) { $ret = \substr($ret, 0, \strlen($ret) - 5); }
        else { $nullable = 0; }
        if (\preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $ret) !== 1
            || \preg_match('/@(?:phpstan-|psalm-)?template(?:-covariant)?[ \t]+' . $ret . '(?![A-Za-z0-9_])/', $doc) !== 1) {
            return -1;
        }
        foreach ($params as $i => $p) {
            $pt = \Semantic\DocNames::rawTag($doc, '@param', $p->name);
            if ($pt !== null && \str_replace(' ', '', $pt) === 'class-string<' . $ret . '>') {
                return 2 * $i + $nullable;
            }
        }
        return -1;
    }
}
