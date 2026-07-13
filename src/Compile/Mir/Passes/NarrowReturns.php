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
        // Only un-resolved returns (bare `array` / no hint) are candidates.
        if ($fn->returnType->kind !== Type::KIND_UNKNOWN) { return false; }
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
        $first = $returns[0]->value->type;
        if (!$first->isArray()) { return false; }
        $isAssoc = $first->isAssoc();
        $elem = null;
        $key = null;
        foreach ($returns as $ret) {
            $t = $ret->value->type;
            // All returns must agree on the array shape (vec vs assoc).
            if (!$t->isArray() || $t->isAssoc() !== $isAssoc) { return false; }
            $e = $t->element ?? Type::unknown();
            $elem = $elem === null ? $e : $elem->unionWith($e);
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
        $fn->returnType = $result;
        return true;
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
