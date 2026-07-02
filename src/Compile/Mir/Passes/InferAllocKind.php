<?php

namespace Compile\Mir\Passes;

use Compile\Mir\AllocationKind;
use Compile\Mir\FunctionDef;
use Compile\Mir\Module;
use Compile\Mir\Node;
use Compile\Mir\Pass;
use Compile\Mir\Walk;

/**
 * Allocation-kind inference (contract step #4). Intraprocedural escape
 * analysis that stamps every allocating node (effects->alloc) with an
 * {@see AllocationKind}: RcHeap when its value can escape the frame,
 * NoRefcount when it is provably frame-confined.
 *
 * The verdict is what the future MemoryOps lowering (#5) consumes to
 * decide retain/release vs scope-exit free — keeping that decision out
 * of EmitLlvm's feature handlers.
 *
 * Method — top-down context threading. `escCtx` = "a value produced at
 * this position would escape the function frame". A node escapes when
 * its position is an escape sink:
 *  - return / throw value
 *  - any heap-store value (property / element / static-prop / dyn-prop)
 *  - any call / new / method / static / invoke argument (and method
 *    receiver, conservatively — the callee may retain `$this`)
 *  - an array-literal element value (flows into the container)
 *  - a closure capture / spread / ref-alias source
 *  - assignment into a local that itself escapes
 * Transparent nodes (ternary, match, `??`) forward the parent context
 * to their value branches.
 *
 * Escaping locals are found by a monotonic fixpoint: a `$x` read in an
 * escaping position escapes; `$a = $b` propagates escape from `$a` to
 * `$b`. By-ref params, static-locals, ref bindings and by-ref foreach
 * vars are seeded as escaping (their writes outlive the frame).
 *
 * Soundness: defaults to RcHeap; only the proven-confined case is
 * downgraded. Over-marking RcHeap is safe, under-marking is a UAF.
 */
final class InferAllocKind implements Pass
{
    public const NAME = 'infer-alloc-kind';

    public function name(): string { return self::NAME; }

    public function requires(): array { return [InferEffects::NAME]; }

    /** @var array<string, bool> locals whose value may escape the frame */
    private array $escaping = [];

    public function run(Module $module): Module
    {
        foreach ($module->functions as $fn) {
            $this->analyzeFunction($fn);
        }
        $module->markPassApplied(self::NAME);
        return $module;
    }

    private function analyzeFunction(FunctionDef $fn): void
    {
        $this->escaping = [];
        // Seed names whose stores outlive the frame regardless of reads.
        foreach ($fn->params as $p) {
            if ($p->byRef) { $this->escaping[$p->name] = true; }
        }
        $this->seedBindings($fn->body);

        // Fixpoint: re-collect escaping locals until the set is stable.
        // Monotonic (only ever adds), so it terminates; bound defensively
        // by the number of distinct locals + 1.
        $guard = 0;
        while (true) {
            $before = \count($this->escaping);
            $this->traverse($fn->body, false, true);
            $after = \count($this->escaping);
            $guard = $guard + 1;
            if ($after === $before) { break; }
            if ($guard > 100) { break; }
        }

        // Assign verdicts with the settled escaping set.
        $this->traverse($fn->body, false, false);
    }

    /**
     * Pre-mark names bound in ways that always escape: static-local
     * cells, ref aliases / binds, by-ref foreach vars.
     */
    private function seedBindings(Node $n): void
    {
        $k = $n->kind;
        if ($k === Node::KIND_STATIC_LOCAL_DECL) {
            $this->escaping[$this->asStaticLocalDecl($n)->name] = true;
        } elseif ($k === Node::KIND_REF_ALIAS) {
            $ra = $this->asRefAlias($n);
            $this->escaping[$ra->target] = true;
            $this->escaping[$ra->source] = true;
        } elseif ($k === Node::KIND_REF_BIND) {
            $this->escaping[$this->asRefBind($n)->target] = true;
        } elseif ($k === Node::KIND_FOREACH) {
            $fe = $this->asForeach($n);
            if ($fe->byRef) { $this->escaping[$fe->valueVar] = true; }
        }
        foreach (Walk::children($n) as $c) { $this->seedBindings($c); }
    }

    /**
     * Walk `$n` carrying whether its produced value escapes. When
     * `$collecting`, mark escaping locals; otherwise stamp alloc kinds.
     */
    private function traverse(Node $n, bool $escCtx, bool $collecting): void
    {
        $k = $n->kind;

        if ($collecting) {
            if ($k === Node::KIND_LOAD_LOCAL && $escCtx) {
                $this->escaping[$this->asLoadLocal($n)->name] = true;
            }
        } else {
            $e = $n->effects;
            // Unified PhpArray has no arena path (every buffer is malloc'd),
            // so a confined array local — like a confined `new X()` — must be
            // rc-managed or it leaks every call (isOwnedObj only releases
            // RC_HEAP). Force RC_HEAP for ALL array literals (incl. an empty
            // `[]` that grows via set/append and carries no alloc effect node).
            $uniArr = $k === Node::KIND_ARRAY_LIT;
            if (($e !== null && $e->alloc) || $uniArr) {
                // Objects are ALWAYS heap-allocated (emitNewObj has no arena
                // path), so a confined `new X()` local must still be rc-managed
                // — otherwise isOwnedObj never gives it a scope-exit release and
                // it leaks every call. Force RC_HEAP for objects; other allocs
                // (vec/assoc/string) keep the arena-eligible NoRefcount path.
                //
                // A unified array literal is normally forced RC_HEAP (no arena
                // path). Under Debug::$arenaArrays a NON-ESCAPING array whose
                // elements need NO per-element drop (plain int/float/bool
                // scalars) becomes arena: its release bails on the arena tag,
                // which is sound precisely because there is nothing to free per
                // element. Arrays with string / cell / nested-array / object
                // elements stay RC_HEAP (an arena outer whose release bails
                // would leak those owned heap payloads).
                $arenaArr = $uniArr && !$escCtx && $this->isArenaScalarArray($n);
                $forceHeap = $escCtx || $k === Node::KIND_NEW_OBJ || $k === Node::KIND_CLONE
                    || ($uniArr && !$arenaArr);
                $n->allocKind = $forceHeap
                    ? AllocationKind::RC_HEAP
                    : AllocationKind::NO_REFCOUNT;
            }
        }

        // ── escape sinks: value child(ren) escape ──
        if ($k === Node::KIND_RETURN) {
            $v = $this->asReturn($n)->value;
            if ($v !== null) { $this->traverse($v, true, $collecting); }
            return;
        }
        if ($k === Node::KIND_THROW) {
            $this->traverse($this->asThrow($n)->value, true, $collecting);
            return;
        }
        if ($k === Node::KIND_STORE_LOCAL) {
            $sl = $this->asStoreLocal($n);
            $val = $sl->value;
            // Aliasing a mutable container (`$b = $a` where $a is a
            // vec/assoc/obj) is unsafe to arena: the two locals share one
            // buffer, so growing one would move/free the other's pointer.
            // Force the source to escape (→ RcHeap) so arena only ever
            // holds un-aliased containers. Strings are exempt — immutable,
            // so a shared buffer is never relocated.
            if ($val->kind === Node::KIND_LOAD_LOCAL
                && $this->isMutableContainer($val->type)) {
                $this->traverse($val, true, $collecting);
                return;
            }
            // A string self-append (`$s = $s . rhs`, i.e. `.=`) must be
            // rc-heap, not arena: EmitLlvm lowers it to an in-place
            // __mir_str_append that needs rc==1 ownership and a scope-exit
            // release (an arena/immortal accumulator re-copies on every
            // append — the O(n²) trap — AND would leak the grown heap
            // buffer, which carries no arena bulk-free). Force escape so
            // the accumulator and its concat land on the rc heap.
            if ($this->isStringSelfAppend($sl)) {
                $this->escaping[$sl->name] = true;
                $this->traverse($val, true, $collecting);
                return;
            }
            $tgtEscapes = isset($this->escaping[$sl->name]);
            $this->traverse($val, $tgtEscapes, $collecting);
            return;
        }
        if ($k === Node::KIND_STORE_PROPERTY) {
            $sp = $this->asStoreProperty($n);
            $this->traverse($sp->object, false, $collecting);
            $this->traverse($sp->value, true, $collecting);
            return;
        }
        if ($k === Node::KIND_STORE_ELEMENT) {
            $se = $this->asStoreElement($n);
            $this->traverse($se->array, false, $collecting);
            // The key may be retained by the container (assoc strdup-free
            // keys), so an allocated key escapes — conservatively RcHeap.
            $this->traverse($se->index, true, $collecting);
            $this->traverse($se->value, true, $collecting);
            return;
        }
        if ($k === Node::KIND_STORE_STATIC_PROP) {
            $this->traverse($this->asStoreStaticProp($n)->value, true, $collecting);
            return;
        }
        if ($k === Node::KIND_STORE_DYN_PROP) {
            $sd = $this->asStoreDynProp($n);
            $this->traverse($sd->object, false, $collecting);
            $this->traverse($sd->name, false, $collecting);
            $this->traverse($sd->value, true, $collecting);
            return;
        }

        // ── calls: every argument escapes — the callee may retain it —
        // EXCEPT a known non-retaining builtin (a pure reader returning a
        // scalar, which cannot alias its arg). Borrowing the arg keeps a
        // confined producer (a concat / substr temp passed to strlen / strpos)
        // on the arena instead of forcing it to the rc heap. */
        if ($k === Node::KIND_CALL) {
            $call = $this->asCall($n);
            $argEsc = !$this->isBorrowingBuiltin($call->function);
            foreach ($call->args as $a) { $this->traverse($a, $argEsc, $collecting); }
            return;
        }
        if ($k === Node::KIND_STATIC_CALL) {
            foreach ($this->asStaticCall($n)->args as $a) { $this->traverse($a, true, $collecting); }
            return;
        }
        if ($k === Node::KIND_NEW_OBJ) {
            foreach ($this->asNewObj($n)->args as $a) { $this->traverse($a, true, $collecting); }
            return;
        }
        if ($k === Node::KIND_CLONE) {
            $cl = $this->asClone($n);
            $this->traverse($cl->object, true, $collecting);
            foreach ($cl->withProps as $pair) { $this->traverse($pair->value, true, $collecting); }
            return;
        }
        if ($k === Node::KIND_METHOD_CALL) {
            $mc = $this->asMethodCall($n);
            $this->traverse($mc->object, true, $collecting);
            foreach ($mc->args as $a) { $this->traverse($a, true, $collecting); }
            return;
        }
        if ($k === Node::KIND_INVOKE) {
            $iv = $this->asInvoke($n);
            $this->traverse($iv->callee, true, $collecting);
            foreach ($iv->args as $a) { $this->traverse($a, true, $collecting); }
            return;
        }

        // ── aggregate / capture: element values escape into the container ──
        if ($k === Node::KIND_ARRAY_LIT) {
            foreach ($this->asArrayLit($n)->elements as $el) {
                if ($el->key !== null) { $this->traverse($el->key, false, $collecting); }
                $this->traverse($el->value, true, $collecting);
            }
            return;
        }
        if ($k === Node::KIND_CLOSURE) {
            foreach ($this->asClosure($n)->captures as $c) { $this->traverse($c, true, $collecting); }
            return;
        }
        if ($k === Node::KIND_SPREAD) {
            $this->traverse($this->asSpread($n)->operand, true, $collecting);
            return;
        }

        // ── transparent: branches inherit the parent context ──
        if ($k === Node::KIND_TERNARY) {
            $t = $this->asTernary($n);
            $this->traverse($t->cond, false, $collecting);
            if ($t->then !== null) { $this->traverse($t->then, $escCtx, $collecting); }
            $this->traverse($t->else_, $escCtx, $collecting);
            return;
        }
        if ($k === Node::KIND_NULLCOALESCE) {
            $nc = $this->asNullCoalesce($n);
            $this->traverse($nc->left, $escCtx, $collecting);
            $this->traverse($nc->right, $escCtx, $collecting);
            return;
        }
        if ($k === Node::KIND_MATCH) {
            $m = $this->asMatch($n);
            $this->traverse($m->subject, false, $collecting);
            foreach ($m->arms as $arm) {
                $conds = $arm->conds;
                if ($conds !== null) {
                    foreach ($conds as $c) { $this->traverse($c, false, $collecting); }
                }
                $this->traverse($arm->body, $escCtx, $collecting);
            }
            return;
        }

        // ── default: children are non-escaping reads ──
        foreach (Walk::children($n) as $c) { $this->traverse($c, false, $collecting); }
    }

    /**
     * Builtins that only READ their arguments and return a SCALAR — they can't
     * retain or alias an arg past the call, so the arg stays frame-confined
     * (arena), not rc-heap. Conservative whitelist: under-marking escape is a
     * UAF, so list only proven pure scalar-returning readers. Excludes anything
     * that returns/stores the arg (substr, array_*, sprintf, …).
     */
    private function isBorrowingBuiltin(string $fn): bool
    {
        return $fn === 'strlen' || $fn === 'mb_strlen' || $fn === 'count' || $fn === 'sizeof'
            || $fn === 'strpos' || $fn === 'stripos' || $fn === 'strrpos' || $fn === 'strripos'
            || $fn === 'str_contains' || $fn === 'str_starts_with' || $fn === 'str_ends_with'
            || $fn === 'substr_count' || $fn === 'ord'
            || $fn === 'strcmp' || $fn === 'strcasecmp' || $fn === 'strncmp' || $fn === 'strncasecmp'
            || $fn === 'is_string' || $fn === 'is_int' || $fn === 'is_integer' || $fn === 'is_array'
            || $fn === 'is_bool' || $fn === 'is_float' || $fn === 'is_null' || $fn === 'is_object'
            || $fn === 'is_numeric' || $fn === 'is_callable' || $fn === 'is_scalar'
            || $fn === 'gettype' || $fn === 'get_debug_type'
            || $fn === 'intval' || $fn === 'floatval' || $fn === 'boolval' || $fn === 'doubleval'
            || $fn === 'var_dump' || $fn === 'print_r' || $fn === 'printf';
    }

    /** `$s = $s . rhs` on a string local — the `.=` self-append shape. */
    private function isStringSelfAppend(\Compile\Mir\StoreLocal $sl): bool
    {
        $v = $sl->value;
        if ($v->kind !== Node::KIND_CONCAT
            || $v->type->kind !== \Compile\Mir\Type::KIND_STRING) {
            return false;
        }
        $left = $this->asConcat($v)->left;
        return $left->kind === Node::KIND_LOAD_LOCAL
            && $left->type->kind === \Compile\Mir\Type::KIND_STRING
            && $this->asLoadLocal($left)->name === $sl->name;
    }

    private function asConcat(Node $n): \Compile\Mir\Concat { return $n; }

    /**
     * Arena-eligible iff the array needs NO per-element AND no per-key drop:
     *  - VALUE element is a plain int / float / bool scalar (raw i64 / double
     *    slots — nothing to rc-release). String / cell / nested-array / object
     *    values are heap payloads an arena outer (whose release bails on the
     *    tag) would leak.
     *  - KEYS are int (vec, key===null) or explicitly int — NEVER string or
     *    cell. `__mir_array_set_str` rc-retains string keys; on an arena
     *    release-bail those retained heap-string keys would leak. Int keys are
     *    stored raw (no retain), so a vec that later promotes to a hashed
     *    INT-keyed map via a sparse key stays sound.
     * First cut of the arena-arrays epic: the numeric-array hot paths.
     */
    private function isArenaScalarArray(Node $n): bool
    {
        if (!\Compile\Debug::$arenaArrays) { return false; }
        $t = $n->type;
        if ($t === null || !$t->isArray()) { return false; }
        $el = $t->element;
        if ($el === null) { return false; }
        $ek = $el->kind;
        $scalarVal = $ek === \Compile\Mir\Type::KIND_INT
            || $ek === \Compile\Mir\Type::KIND_FLOAT
            || $ek === \Compile\Mir\Type::KIND_BOOL;
        if (!$scalarVal) { return false; }
        $key = $t->key;
        return $key === null || $key->kind === \Compile\Mir\Type::KIND_INT;
    }

    private function isMutableContainer(\Compile\Mir\Type $t): bool
    {
        $k = $t->kind;
        return $k === \Compile\Mir\Type::KIND_ARRAY
            || $k === \Compile\Mir\Type::KIND_OBJ;
    }

    private function asLoadLocal(Node $n): \Compile\Mir\LoadLocal { return $n; }
    private function asStoreLocal(Node $n): \Compile\Mir\StoreLocal { return $n; }
    private function asReturn(Node $n): \Compile\Mir\Return_ { return $n; }
    private function asThrow(Node $n): \Compile\Mir\Throw_ { return $n; }
    private function asStoreProperty(Node $n): \Compile\Mir\StoreProperty { return $n; }
    private function asStoreElement(Node $n): \Compile\Mir\StoreElement { return $n; }
    private function asStoreStaticProp(Node $n): \Compile\Mir\StoreStaticProp_ { return $n; }
    private function asStoreDynProp(Node $n): \Compile\Mir\StoreDynProp_ { return $n; }
    private function asCall(Node $n): \Compile\Mir\Call { return $n; }
    private function asStaticCall(Node $n): \Compile\Mir\StaticCall_ { return $n; }
    private function asNewObj(Node $n): \Compile\Mir\NewObj { return $n; }
    private function asClone(Node $n): \Compile\Mir\Clone_ { return $n; }
    private function asMethodCall(Node $n): \Compile\Mir\MethodCall_ { return $n; }
    private function asInvoke(Node $n): \Compile\Mir\Invoke_ { return $n; }
    private function asArrayLit(Node $n): \Compile\Mir\ArrayLit { return $n; }
    private function asClosure(Node $n): \Compile\Mir\Closure_ { return $n; }
    private function asSpread(Node $n): \Compile\Mir\Spread_ { return $n; }
    private function asTernary(Node $n): \Compile\Mir\Ternary { return $n; }
    private function asNullCoalesce(Node $n): \Compile\Mir\NullCoalesce_ { return $n; }
    private function asMatch(Node $n): \Compile\Mir\Match_ { return $n; }
    private function asRefAlias(Node $n): \Compile\Mir\RefAlias_ { return $n; }
    private function asRefBind(Node $n): \Compile\Mir\RefBind_ { return $n; }
    private function asStaticLocalDecl(Node $n): \Compile\Mir\StaticLocalDecl_ { return $n; }
    private function asForeach(Node $n): \Compile\Mir\Foreach_ { return $n; }
}
