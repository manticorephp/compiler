<?php

namespace Compile\Mir\Passes;

use Compile\Mir\FunctionDef;
use Compile\Mir\Module;
use Compile\Mir\Node;
use Compile\Mir\Pass;
use Compile\Mir\Return_;
use Compile\Mir\Type;
use Compile\Mir\Walk;

/**
 * Narrow an `array` / un-hinted function return from `unknown` to a
 * concrete `vec[T]` when every value-returning path yields a vec.
 *
 * The `array` type hint lowers to `unknown` ({@see LowerFromAst::
 * lowerTypeHint}), so a `function build(): array` that only does
 * positional append / array-literal returns is invisible to the rc
 * machinery — its call result is `unknown`, never tracked, and the
 * returned (+1 owned) vec leaks at every call site.
 *
 * This pass closes that gap by body analysis: if all of a function's
 * `return $v` statements carry a `vec` type (and at least one does),
 * its `returnType` is rewritten to that vec. {@see InsertMemoryOps}
 * then sees the call result as an owned vec and plans its release.
 * The LLVM ABI is uniform i64, so narrowing the return type never
 * changes the emitted signature — only the rc decisions.
 *
 * Runs to a fixpoint, re-running {@see InferTypes} after each sweep so
 * chained array-returning callers (`A` returns `B()` returns vec) pick
 * up the narrowed sigs. Conservative: a single non-vec value-return
 * (assoc / scalar / null) leaves the function `unknown` untouched, so
 * soundness of the rc discipline is preserved.
 */
final class NarrowReturns implements Pass
{
    public const NAME = 'narrow-returns';

    /**
     * Accumulator for {@see collectReturns}. Kept on `$this` rather than
     * a by-ref param — the self-host backend drops writes to `array &$p`
     * across nested recursive calls.
     * @var Return_[]
     */
    private array $collected = [];

    /** @var array<string, \Compile\Mir\ClassDef> */
    private array $classes = [];
    /** @var array<string, \Compile\Mir\EnumDef> */
    private array $enums = [];

    /**
     * @param bool $concreteOnly when true, narrow ONLY to a concretely-shaped
     *   array (definite element/key). Used for an EARLY pass before Monomorphize
     *   so a literal-returning `mk(){ return ["x"=>1]; }` gets a concrete sig
     *   (callers fuse / specialize on it) WITHOUT prematurely locking an erased
     *   helper return (`f(array $a){ return array_map(fn,$a); }` → vec[unknown])
     *   that Monomorphize must specialize first. The default (post-Mono) pass
     *   narrows every agreeing array return.
     */
    public function __construct(private bool $concreteOnly = false) {}

    public function name(): string { return self::NAME; }

    /** @return string[] */
    public function requires(): array { return [InferTypes::NAME]; }

    public function run(Module $module): Module
    {
        $this->classes = $module->classes;
        $this->enums = $module->enums;
        // A GENERIC method's body is shared by every instantiation, so its
        // return type is deliberately erased (a cell carrying its tag). Narrowing
        // it to whatever one call site happened to store would type every OTHER
        // instantiation wrong — with a `Box<string>` and a `Box<float>` in the
        // same program, the float store narrowed `get()` to float and the string
        // came back as a double's bit pattern.
        $generic = [];
        foreach ($module->classes as $cd) {
            foreach ($cd->genericReturns as $m => $ignored) {
                $generic[$cd->name . '__' . $m] = true;
            }
        }
        // Bounded fixpoint: each productive sweep narrows >=1 function,
        // which is monotonic, so the function count caps the iterations.
        $iters = 0;
        $max = \count($module->functions) + 2;
        while ($iters < $max) {
            $iters = $iters + 1;
            $changed = false;
            foreach ($module->functions as $fn) {
                if (isset($generic[$fn->name])) { continue; }
                if ($this->narrowFunction($fn)) { $changed = true; }
            }
            if (!$changed) { break; }
            $infer = new InferTypes();
            $infer->run($module);
        }
        $module->markPassApplied(self::NAME);
        return $module;
    }

    /** Returns true if the function's return type was narrowed. */
    private function narrowFunction(FunctionDef $fn): bool
    {
        // A reflection invoke trampoline (__mc_rtramp_*) has NO direct MIR caller
        // — it is reached only through the indirect `__mc_refl_invoke` builtin,
        // whose ABI fixes the return as a boxed cell. Narrowing its `mixed`
        // return to a concrete scalar would drop the box, so the indirect caller
        // reads a raw int as a cell (`invoke(sum())` → 3.45e-323 not 7). Its
        // return type is a hard contract, not an inferred convenience.
        if (\Compile\Mir\Passes\TrampolineSynth::isSynthReturn($fn->name)) { return false; }
        // Only un-resolved returns (bare `array` / no hint) are candidates —
        // EXCEPT the full pass may WIDEN an already-narrowed concrete scalar-element
        // array to a CELL element. The early (concreteOnly) pass can lock a
        // `vec[string]` from a pre-convergence view of a `null|string` accumulator
        // (`$r[] = $c ? null : $s`) that later settles to `vec[cell]`; a caller then
        // reads the box_null cells as raw string pointers → var_dump SIGSEGV. This
        // widen is monotone (cell is the top element, so it never re-fires) and only
        // ever moves scalar → cell, never the reverse.
        $rt = $fn->returnType;
        $mayWiden = !$this->concreteOnly && $rt->isArray() && $rt->element !== null
            && $rt->element->kind !== Type::KIND_CELL
            && $rt->element->kind !== Type::KIND_UNKNOWN;
        if ($rt->kind !== Type::KIND_UNKNOWN && !$mayWiden) { return false; }
        // Early (concreteOnly) pass: a function with an ERASED param (bare
        // `array` / unknown) has a return that Monomorphize can still re-shape
        // per call site (`flip(array $a){ $o[$v]=… }` becomes assoc once $a is
        // vec[string]). Narrowing it now from the erased view (vec[int]) would
        // lock the wrong type. Defer such functions to the post-Mono pass; only
        // param-independent returns (a literal-returning `mk()`) narrow early.
        if ($this->concreteOnly && $this->hasErasedParam($fn)) { return false; }
        $this->collected = [];
        $this->collectReturns($fn->body);
        $returns = $this->collected;
        if (\count($returns) === 0) { return false; }
        // All value-returns must agree on a single container kind (vec or
        // assoc). A bare `array` hint lowers to `unknown`, so without this
        // the caller types the result `unknown` and reads `$r["k"]` as a
        // vec index (the key pointer as an i64 offset) → wild read.
        // A `?array` fn returns null on some path. Skip the null arms when
        // picking the shape and when joining: a null rides an array slot raw as
        // ptr 0 (an allocated `[]` is a real pointer, so they never collide), so
        // the array type is still the honest one. Without this a single
        // `return null;` left the whole fn `unknown` and the caller rendered the
        // returned array's pointer as `int(<ptr>)`.
        $returns = $this->nonNullReturns($returns);
        if (\count($returns) === 0) { return false; }
        $first = $returns[0]->value->type;
        // An OBJECT return. This pass was built for bare-`array` erasure, so it
        // only ever narrowed arrays — a function returning an object, or a union
        // of them, kept its `unknown` return and the caller could resolve NOTHING
        // on the result: `pick(true)->speak()` rendered its string as a raw
        // pointer. Join the returns into the one class they all are, or the union
        // of the classes they can be, so a method call dispatches on class_id.
        //
        // Only in the FULL pass: the early one exists to leave shapes that
        // Monomorphize will still change, and an object return is not one.
        if (!$this->concreteOnly && $this->isObjectish($first)) {
            return $this->narrowObjectReturn($fn, $returns);
        }
        if (!$first->isArray()) { return false; }
        // An empty / unrefined array literal (`[]` → vec[unknown]) is
        // shape-agnostic: php's `[]` is compatible with a vec AND an assoc
        // sibling. Take the vec-vs-assoc shape from the first CONCRETE-element
        // return and let an unrefined arm defer to it, instead of the first
        // return fixing the shape (so `if (!$xs) return []; return ["k"=>…];`
        // narrows to the assoc rather than conflicting to `unknown`).
        $shapeRef = $first;
        foreach ($returns as $ret) {
            $t = $ret->value->type;
            if ($t->isArray() && $t->element !== null
                && $t->element->kind !== Type::KIND_UNKNOWN) {
                $shapeRef = $t;
                break;
            }
        }
        $isAssoc = $shapeRef->isAssoc();
        $elem = null;
        $key = null;
        foreach ($returns as $ret) {
            $t = $ret->value->type;
            if (!$t->isArray()) { return false; }
            $unrefined = $t->element === null || $t->element->kind === Type::KIND_UNKNOWN;
            // A concrete array must agree on the shape; an unrefined `[]` defers
            // (contributes no element/key info).
            if (!$unrefined && $t->isAssoc() !== $isAssoc) { return false; }
            if ($unrefined) { continue; }
            $elem = $elem === null ? $t->element : $this->joinElem($elem, $t->element);
            if ($isAssoc) {
                $k = $t->key ?? Type::unknown();
                $key = $key === null ? $k : $key->unionWith($k);
            }
        }
        if ($elem === null) { $elem = Type::unknown(); }
        $result = $isAssoc
            ? Type::assoc($key ?? Type::unknown(), $elem)
            : Type::vec($elem);
        // Early (pre-Monomorphize) pass: only adopt a fully concrete shape, so an
        // erased helper return (vec[unknown] from array_map over a bare-array
        // param) is left for Monomorphize to specialize then the post-Mono pass.
        if ($this->concreteOnly && !$this->isConcreteArray($result)) { return false; }
        // Widening an already-narrowed return: only accept a CELL-element result
        // (the scalar → cell correction) and only when it actually differs — never
        // re-narrow a concrete scalar to a different scalar (would not converge).
        if ($mayWiden) {
            if ($result->element === null || $result->element->kind !== Type::KIND_CELL) {
                return false;
            }
            if ($rt->isAssoc() === $result->isAssoc()
                && $rt->element !== null && $rt->element->kind === $result->element->kind) {
                return false;
            }
        }
        $fn->returnType = $result;
        return true;
    }

    /**
     * Narrow a function whose every return is an object to that class, or to the
     * union of the classes it can be.
     *
     * @param \Compile\Mir\Return_[] $returns
     */
    private function narrowObjectReturn(FunctionDef $fn, array $returns): bool
    {
        $names = [];
        $atoms = [];
        foreach ($returns as $ret) {
            $t = $ret->value->type;
            if (!$this->isObjectish($t)) { return false; }
            foreach ($this->atomsOf($t) as $a) {
                $cls = $a->class ?? '';
                if (!$this->isPlainClass($cls)) { return false; }
                if (isset($names[$cls])) { continue; }
                $names[$cls] = true;
                $atoms[] = $a;
            }
        }
        if ($atoms === []) { return false; }
        $result = \count($atoms) === 1 ? $atoms[0] : Type::union($atoms);
        if ($result->kind === $fn->returnType->kind
            && ($result->class ?? '') === ($fn->returnType->class ?? '')) {
            return false;
        }
        $fn->returnType = $result;
        return true;
    }

    /**
     * Join two element types for a return join. `Type::unionWith` answers
     * `unknown` for ANY kind mismatch, which is a LIE about a heterogeneous
     * element: `vec[cell]` (a `[false,'loc']` literal, already repaired by
     * {@see InferNodes::inferArrayLit}) joined with `vec[string]` came back
     * `vec[unknown]`, and the reader then rendered a boxed `false` as a raw
     * pointer. A value pair that disagrees is a CELL — it carries its own tag.
     *
     * Mirrors {@see InferTypes::unifyToCell}/`isValueKind`, which this pass
     * cannot reach (it is a Pass class, not the InferTypes trait host); the
     * same duplication `InferTypes::arrayElemMerge` already lives with.
     */
    private function joinElem(Type $a, Type $b): Type
    {
        // UNKNOWN is the "not inferred yet" bottom, not "anything" — defer to the
        // concrete sibling (mirrors Type::joinElement). Without this a function
        // with `return [];` (vec[unknown]) beside `return explode(…)`
        // (vec[string]) narrowed to vec[unknown] and the caller read each string
        // element's pointer as a raw i64 (var_dump printed float garbage). The
        // very common `if (!$xs) return []; return <built array>;` shape.
        if ($a->kind === Type::KIND_UNKNOWN) { return $b; }
        if ($b->kind === Type::KIND_UNKNOWN) { return $a; }
        $j = $a->unionWith($b);
        if ($j->kind !== Type::KIND_UNKNOWN) { return $j; }
        if ($a->kind === Type::KIND_CELL || $b->kind === Type::KIND_CELL
            || ($this->isValueKind($a) && $this->isValueKind($b))) {
            return Type::cell();
        }
        return $j;
    }

    /** A concrete value-carrying kind, boxable into a cell. Mirror of
     *  {@see InferTypes::isValueKind}. */
    private function isValueKind(Type $t): bool
    {
        $k = $t->kind;
        return $k === Type::KIND_INT || $k === Type::KIND_FLOAT
            || $k === Type::KIND_STRING || $k === Type::KIND_BOOL
            || $k === Type::KIND_ARRAY || $k === Type::KIND_OBJ;
    }

    /**
     * The value-returns that are not a bare `null` ({@see narrowFunction}).
     * Kept on `$this`-free plain locals — a `Return_[]` in, a `Return_[]` out.
     *
     * @param \Compile\Mir\Return_[] $returns
     * @return \Compile\Mir\Return_[]
     */
    private function nonNullReturns(array $returns): array
    {
        $out = [];
        foreach ($returns as $ret) {
            if ($ret->value->type->kind === Type::KIND_NULL) { continue; }
            $out[] = $ret;
        }
        return $out;
    }

    /** An object, or a static union of object classes. */
    private function isObjectish(Type $t): bool
    {
        return $t->kind === Type::KIND_OBJ || $t->kind === Type::KIND_UNION;
    }

    /**
     * A plain user class — one whose value really is just a pointer to an object
     * of that layout.
     *
     * A Generator carries its yielded element/key IN the type, so narrowing to a
     * bare `obj<Generator>` would DROP them. A closure has no rc header and must
     * never be rc-managed as an object. An enum case is an ordinal, not a pointer.
     * None of the three may be joined into an object union.
     */
    private function isPlainClass(string $cls): bool
    {
        if ($cls === '' || $cls === 'Generator' || $cls === 'Closure') { return false; }
        if (\str_starts_with($cls, '__closure_')) { return false; }
        if (isset($this->enums[$cls])) { return false; }
        return isset($this->classes[$cls]);
    }

    /**
     * The object arms a type can be.
     *
     * @return Type[]
     */
    private function atomsOf(Type $t): array
    {
        if ($t->kind === Type::KIND_UNION) { return $t->atoms; }
        return [$t];
    }

    /** Any param is an erased array (bare `array` → unknown, or vec[unknown]) —
     *  its concrete shape, and thus the return, is only known after Monomorphize. */
    private function hasErasedParam(FunctionDef $fn): bool
    {
        foreach ($fn->params as $p) {
            $t = $p->type;
            if ($t->kind === Type::KIND_UNKNOWN) { return true; }
            if ($t->isVec()) {
                $e = $t->element;
                if ($e === null || $e->kind === Type::KIND_UNKNOWN) { return true; }
            }
        }
        return false;
    }

    /** An array whose element (and key, if assoc) is a definite type. */
    private function isConcreteArray(Type $t): bool
    {
        if (!$t->isArray()) { return false; }
        $e = $t->element;
        if ($e === null || !$this->isConcreteElem($e)) { return false; }
        if ($t->isAssoc()) {
            $key = $t->key;
            if ($key === null || !$this->isConcreteElem($key)) { return false; }
        }
        return true;
    }

    private function isConcreteElem(Type $t): bool
    {
        $k = $t->kind;
        if ($k === Type::KIND_UNKNOWN || $k === Type::KIND_CELL || $k === Type::KIND_VOID) {
            return false;
        }
        if ($k === Type::KIND_ARRAY) { return $this->isConcreteArray($t); }
        return true;
    }

    /**
     * Collect value-bearing `return` nodes from the function body into
     * `$this->collected`. Walk never descends into closure bodies (a
     * `closure` node exposes only its captures), so nested-closure
     * returns stay with their own FunctionDef.
     */
    private function collectReturns(Node $n): void
    {
        if ($n->kind === Node::KIND_RETURN) {
            if ($n->value !== null) { $this->collected[] = $n; }
            return;
        }
        foreach (Walk::children($n) as $c) { $this->collectReturns($c); }
    }
}
