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

    /** True if `$hint` is a union (`a|b|…`) whose every arm is an ARRAY shape
     *  (`X[]` or `array<…>`) — e.g. `int[]|float[]`, `string[]|int[]`. */
    private function isArrayUnion(string $hint): bool
    {
        $parts = \explode('|', \str_replace(['(', ')'], '', $hint));
        if (\count($parts) < 2) { return false; }
        foreach ($parts as $p) {
            if (!$this->looksLikeArrayElemType(\trim($p))) { return false; }
        }
        return true;
    }

    /** True if `$hint` is a union (`a|b|…`) whose every arm is `int` or `float`
     *  (`int|float`, `float|int`) — a purely-numeric scalar union. */
    private function isNumericUnion(string $hint): bool
    {
        $parts = \explode('|', \str_replace(['(', ')', '?', '\\'], '', $hint));
        if (\count($parts) < 2) { return false; }
        foreach ($parts as $p) {
            $low = \strtolower(\trim($p));
            if ($low !== 'int' && $low !== 'integer'
                && $low !== 'float' && $low !== 'double') {
                return false;
            }
        }
        return true;
    }

    private function lowerTypeHint(?string $hint): Type
    {
        if ($hint === null) { return Type::unknown(); }
        // A `@template T` parameter of the enclosing class. Checked before any
        // lowercasing — a type-parameter name is case-sensitive. `T[]` and
        // `array<string, T>` reach this through the recursive element/value
        // lowering below, so they need no separate case.
        if ($this->isTypeParam($hint)) {
            // A BOUNDED `T of Animal` carries its bound, so it erases to a raw
            // obj<Animal> pointer rather than a tagged cell.
            return Type::typeVar($hint, $this->currentTypeBounds[$hint] ?? null);
        }
        // `callable(int): string` — a callable with its signature spelled out.
        // Checked BEFORE the union / intersection branches below, whose `|` and
        // `&` scans would otherwise trip over a union INSIDE the parameter list.
        $sig = $this->lowerCallableSignature($hint);
        if ($sig !== null) { return $sig; }
        // `mixed` and union types (`int|string`, DNF `(A&B)|C`) become a tagged
        // cell (NaN-boxed i64): the value carries its runtime type tag. A purely
        // NUMERIC union (`int|float`) is a NUMERIC cell — arithmetic promotes by
        // tag AND a single-kind return/value narrows to the concrete scalar
        // (InferTypes), so e.g. array_sum's float specialization returns a raw
        // double instead of a mantissa-truncating box_float.
        if (\strpos($hint, '|') > 0) {
            // A union whose every arm is an ARRAY shape (`int[]|float[]`,
            // `string[]|int[]`) is still an ARRAY, not a scalar cell — lower it
            // to an erased `vec[unknown]` so call-site element inference /
            // monomorphization can refine the element per call (a plain cell
            // would erase the array-ness and box every element).
            if ($this->isArrayUnion($hint)) { return Type::vec(Type::unknown()); }
            // A purely-NUMERIC scalar union (`int|float`) is a numeric cell:
            // arithmetic promotes by tag and a single-kind return narrows to the
            // concrete scalar (so e.g. array_sum's float specialization returns a
            // raw double, not a mantissa-truncating box_float).
            if ($this->isNumericUnion($hint)) { return Type::numericCell(); }
            return Type::cell();
        }
        // Pure intersection `A&B` (DNF with no top-level union) — the value
        // implements ALL the named types; type it as the FIRST so method
        // dispatch + return-type resolution have a concrete class (the runtime
        // object satisfies the rest, resolved virtually by class_id). Strip any
        // grouping parens the parser preserved.
        $bare = \str_replace(['(', ')'], '', $hint);
        $amp = \strpos($bare, '&');
        if ($amp !== false && $amp > 0) {
            return $this->lowerTypeHint(\substr($bare, 0, $amp));
        }
        $low = strtolower(ltrim($hint, '?\\'));
        if ($low === 'mixed') { return Type::cell(); }
        // `iterable` = array|Traversable — a tagged cell (carries either at
        // runtime). foreach over an `iterable` array works via the cell path;
        // object iteration through an iterable binding needs runtime
        // array-vs-object dispatch (a follow-up).
        if ($low === 'iterable') { return Type::cell(); }
        // A nullable SCALAR (`?int`/`?float`/`?bool`) can't ride a raw i64: null
        // would collide with 0 / 0.0 / false (so `=== null` and var_dump fail).
        // Box it as a NUMERIC cell — null gets the NULL tag, the value its own,
        // arithmetic promotes by tag. The `numeric` flag distinguishes it from a
        // general mixed/array cell (whose property slot stays RAW for the SPL
        // cell-array machinery) so only scalar-nullable props box-null/box-store.
        // (Nullable POINTER types — `?string`/`?array`/`?Obj` — ride raw: a null
        // is ptr 0, distinct from any valid ptr, mapped to NULL by box_ptr/etc.)
        $nullable = \strlen($hint) > 0 && $hint[0] === '?';
        if ($low === 'int' || $low === 'integer') {
            return $nullable ? Type::numericCell() : Type::int_();
        }
        if ($low === 'float' || $low === 'double') {
            return $nullable ? Type::numericCell() : Type::float_();
        }
        if ($low === 'bool' || $low === 'boolean') {
            return $nullable ? Type::numericCell() : Type::bool_();
        }
        if ($low === 'string') { return Type::string_(); }
        if ($low === 'void')   { return Type::void(); }
        if ($low === 'null')   { return Type::null_(); }
        // `\Ffi\Ptr` is a built-in foreign handle (a libc FILE*/DIR*/raw addr):
        // an i64 pointer, excluded from rc, with a runtime null-compare. It must
        // resolve to obj<Ffi\Ptr> even in a module (the stdlib) that does NOT
        // register the Ffi\Ptr class — otherwise it erases to `unknown`, the
        // .sig drops the type, and a cross-module caller boxes the handle (ABI
        // mismatch → the FILE* is read from NaN-boxed bits → crash). PHP has no
        // writable `resource` type, so a `resource` hint is left to the normal
        // (unknown class) path, matching PHP.
        if ($low === 'ffi\\ptr') { return Type::obj('Ffi\\Ptr'); }
        // `\Closure` is a header-less closure struct ([fn_ptr, captures...]),
        // never rc-managed. Typing it KIND_CLOSURE (not obj) keeps every rc
        // path from mis-routing a retain/release through it — the startup
        // `Command::run(\Closure $h)` corruption. (A `\Closure(...): T` doc
        // shape contains '|'/'(' and is handled above as a cell/union.)
        if ($low === 'closure' || $low === 'callable') { return Type::closure(); }
        // `self` / `static` → the enclosing class; `parent` → its base.
        // Without this a `?self $next` property erases to unknown and a
        // `$this->next->method()` dispatch can't resolve the return flavor.
        if ($low === 'self' || $low === 'static') {
            if ($this->currentLowerClass !== '') {
                return Type::obj($this->currentLowerClass);
            }
        }
        if ($low === 'parent') {
            if (isset($this->classTable[$this->currentLowerClass])) {
                $pc = $this->classTable[$this->currentLowerClass]->parent;
                if ($pc !== '') { return Type::obj($pc); }
            }
        }
        // `T[]` suffix → vec[T]. A list shape; assoc-keyed bare-array
        // slots get re-typed by InferTypes::scanAssoc* from string-key use.
        if (\strlen($low) > 2 && \substr($low, \strlen($low) - 2) === '[]') {
            $base = \ltrim($hint, '?\\');
            $elem = \substr($base, 0, \strlen($base) - 2);
            return Type::vec($this->lowerTypeHint($elem));
        }
        // Generic array: `array<V>` → vec[V]; `array<K, V>` → assoc[V].
        if (\strncmp($low, 'array<', 6) === 0) {
            $base = \ltrim($hint, '?\\');
            $lt = \strpos($base, '<');
            $inner = \substr($base, $lt + 1, \strlen($base) - $lt - 2);
            // self-host strpos returns -1 (not false) on miss; guard both.
            $comma = \strpos($inner, ',');
            if ($comma === false || $comma < 0) {
                return Type::vec($this->lowerTypeHint($inner));
            }
            $keyStr = \trim(\substr($inner, 0, $comma));
            $valStr = \trim(\substr($inner, $comma + 1, \strlen($inner) - $comma - 1));
            return Type::assoc($this->lowerTypeHint($keyStr), $this->lowerTypeHint($valStr));
        }
        // `Generator` / `Generator<V>` / `Generator<K, V>` → a Generator whose
        // yielded value is V (the 2nd param when keyed, mirroring PHP's
        // `Generator<TKey, TValue>`). Lets a generator PARAMETER / return hint
        // carry the element type inference can't see across a call boundary.
        if ($low === 'generator') { return Type::generator(null); }
        if (\strncmp($low, 'generator<', 10) === 0) {
            $base = \ltrim($hint, '?\\');
            $lt = \strpos($base, '<');
            $inner = \substr($base, $lt + 1, \strlen($base) - $lt - 2);
            $comma = \strpos($inner, ',');
            if ($comma === false || $comma < 0) {
                return Type::generator($this->lowerTypeHint(\trim($inner)));
            }
            $keyStr = \trim(\substr($inner, 0, $comma));
            $valStr = \trim(\substr($inner, $comma + 1, \strlen($inner) - $comma - 1));
            return Type::generator($this->lowerTypeHint($valStr), $this->lowerTypeHint($keyStr));
        }
        // A generic class use — `Box<Tag>` → obj<Box> carrying the bound args.
        // (`array<…>` and `Generator<…>` are handled above, so this is the
        // user-declared case.) The args are a compile-time payload only: the
        // class has ONE compiled body, and the binding is what lets a call site
        // type `@return T` concretely instead of erasing it to unknown.
        $ltg = \strpos($low, '<');
        if ($ltg !== false && $ltg > 0) {
            $gbase = \ltrim($hint, '?\\');
            $glt = \strpos($gbase, '<');
            $gname = \substr($gbase, 0, $glt);
            $ginner = \substr($gbase, $glt + 1, \strlen($gbase) - $glt - 2);
            if (isset($this->classTable[$gname]) || isset($this->knownClassNames[$gname])) {
                return Type::objOf($gname, $this->lowerTypeArgs($ginner));
            }
        }
        $cls = \ltrim($hint, '?\\');
        // A bare class name → obj<Class> (so method returns / params of
        // a class type carry their class for dispatch + __toString).
        // `Ffi\Ptr` stays obj<Ffi\Ptr> but is treated as an opaque FOREIGN
        // pointer downstream: excluded from rc (InsertMemoryOps) and given
        // a RUNTIME null-compare (EmitLlvm) since fopen/opendir genuinely
        // return NULL at runtime.
        if (isset($this->classTable[$cls]) || isset($this->knownClassNames[$cls])) {
            return Type::obj($cls);
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
                    return Type::obj($qualified);
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
            return Type::obj($this->shortClassFqn[$cls]);
        }
        return Type::unknown();
    }

    /** Whether `$hint` denotes a bare `array` with no element type. */
    private function isBareArrayHint(?string $hint): bool
    {
        if ($hint === null) { return false; }
        $low = \strtolower(\ltrim($hint, '?\\'));
        return $low === 'array';
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
            && $this->looksLikeArrayElemType($docType)) {
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
        if ($hint === null && $docType !== null && $docType !== '') {
            return $docType;
        }
        return $hint;
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
        if (\strncmp(\strtolower(\ltrim($t, '?\\')), 'array<', 6) === 0) { return true; }
        return false;
    }

    /**
     * Extract the type token for a docblock `@param X[] $name` (when
     * `$varName` is non-empty) or `@return X[]` / `@var X[]` (empty
     * `$varName`). Returns the raw token (`PropertyDecl[]`) or null.
     */
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
     * `callable(A, B): R` / `Closure(A): R`, with the parameter and return types
     * carried on the closure type. Null when `$hint` is not that shape.
     *
     * A bare `callable` / `Closure` keeps the plain closure type — it says nothing
     * about the signature, so an invoke through it still returns a tagged cell.
     */
    private function lowerCallableSignature(?string $hint): ?Type
    {
        if ($hint === null) { return null; }
        $h = \ltrim($hint, '?\\');
        $lp = \strpos($h, '(');
        if ($lp === false || $lp <= 0) { return null; }
        $head = \strtolower(\substr($h, 0, $lp));
        if ($head !== 'callable' && $head !== 'closure') { return null; }
        $n = \strlen($h);
        $depth = 0;
        $i = $lp;
        $rp = -1;
        while ($i < $n) {
            $c = \substr($h, $i, 1);
            if ($c === '(') { $depth = $depth + 1; }
            elseif ($c === ')') {
                $depth = $depth - 1;
                if ($depth === 0) { $rp = $i; break; }
            }
            $i = $i + 1;
        }
        if ($rp < 0) { return null; }
        $inner = \substr($h, $lp + 1, $rp - $lp - 1);
        $params = \trim($inner) === '' ? [] : $this->lowerTypeArgs($inner);
        $ret = null;
        $j = $this->skipDocSpaces($h, $rp + 1, $n);
        if ($j < $n && \substr($h, $j, 1) === ':') {
            $j = $this->skipDocSpaces($h, $j + 1, $n);
            $rt = \trim(\substr($h, $j, $n - $j));
            if ($rt !== '') { $ret = $this->lowerTypeHint($rt); }
        }
        return Type::closureOf($ret, $params);
    }

    private function docTagType(?string $doc, string $tag, string $varName): ?string
    {
        if ($doc === null) { return null; }
        $n = \strlen($doc);
        $tlen = \strlen($tag);
        // Single forward scan — self-host `strpos` ignores the offset
        // arg (`@__mir_strpos` is 2-ary), so a positional re-search
        // would loop on the first hit. Walk the buffer once instead,
        // using only bounded `substr`.
        $i = 0;
        while ($i + $tlen <= $n) {
            if (\substr($doc, $i, $tlen) !== $tag) { $i = $i + 1; continue; }
            $j = $i + $tlen;
            // Require a whitespace boundary after the tag (`@param` not
            // `@params`).
            $b = ($j < $n) ? \substr($doc, $j, 1) : '';
            if ($b !== ' ' && $b !== "\t") { $i = $i + 1; continue; }
            while ($j < $n) {
                $c = \substr($doc, $j, 1);
                if ($c !== ' ' && $c !== "\t") { break; }
                $j = $j + 1;
            }
            $typeStart = $j;
            // Angle-bracket-aware: a generic like `array<string, ClassDef>`
            // carries a space after the comma — don't stop the type token
            // on whitespace while inside `<...>`, or the value type is lost.
            $depth = 0;
            while ($j < $n) {
                $c = \substr($doc, $j, 1);
                if ($c === '<') { $depth = $depth + 1; }
                elseif ($c === '>') { if ($depth > 0) { $depth = $depth - 1; } }
                // Paren-aware too, and a `callable(int): int` continues PAST the
                // colon: the type token is `callable(int): int`, not the prefix up
                // to the first space. Truncating it left `callable(int):`, which
                // resolved to no type at all — and once a docblock became the type
                // of an un-hinted param, that segfaulted instead of being ignored.
                elseif ($c === '(') { $depth = $depth + 1; }
                elseif ($c === ')') { if ($depth > 0) { $depth = $depth - 1; } }
                elseif ($depth === 0 && $c === ':') { $j = $this->skipDocSpaces($doc, $j + 1, $n); continue; }
                elseif ($depth === 0
                    && ($c === ' ' || $c === "\t" || $c === "\n" || $c === "\r")) {
                    break;
                }
                $j = $j + 1;
            }
            $type = \substr($doc, $typeStart, $j - $typeStart);
            if ($varName === '') { return $type; }
            while ($j < $n) {
                $c = \substr($doc, $j, 1);
                if ($c !== ' ' && $c !== "\t") { break; }
                $j = $j + 1;
            }
            $want = '$' . $varName;
            $wl = \strlen($want);
            if ($j + $wl <= $n && \substr($doc, $j, $wl) === $want) {
                $after = ($j + $wl < $n) ? \substr($doc, $j + $wl, 1) : ' ';
                if ($after === ' ' || $after === "\t"
                    || $after === "\n" || $after === "\r") {
                    return $type;
                }
            }
            $i = $j;
        }
        return null;
    }
}
