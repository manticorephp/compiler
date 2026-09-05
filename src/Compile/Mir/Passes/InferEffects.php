<?php

namespace Compile\Mir\Passes;

use Compile\Mir\Effects;
use Compile\Mir\FunctionDef;
use Compile\Mir\Module;
use Compile\Mir\Node;
use Compile\Mir\Pass;
use Compile\Mir\Walk;

/**
 * Effect inference (contract step #3). Stamps every MIR node with its
 * intrinsic {@see Effects} mask and stores the per-function union on the
 * {@see FunctionDef}. Pure analysis — never rewrites the tree.
 *
 * "Intrinsic" = what *this* op does, not its subtree. The function
 * aggregate is the union of every node's intrinsic set, which is what
 * a call-graph summary pass (#4) and the MemoryOps lowering (#5) read.
 *
 * Effect rules (v1, deliberately conservative — precision improves
 * when callee summaries land):
 *  - ALLOC:        concat, array-lit, `new`, closure, (string|array|object)
 *                  cast, dynamic-prop store (bag may grow)
 *  - MAY_THROW:    div, mod, `new`, every call form, `throw`
 *  - CALL_UNKNOWN: virtual method call, `$f(...)` invoke
 *  - STORE_HEAP:   property / element / static-prop / dynamic-prop store
 *  - ESCAPE:       non-void return, `throw`, every heap store
 *  - RETAIN/RELEASE: never set here — owned by MemoryOps (#5)
 */
final class InferEffects implements Pass
{
    public const NAME = 'infer-effects';

    public function name(): string { return self::NAME; }

    public function requires(): array { return [InferTypes::NAME]; }

    public function run(Module $module): Module
    {
        foreach ($module->functions as $fn) {
            $fn->effects = $this->walk($fn->body);
        }
        $module->markPassApplied(self::NAME);
        return $module;
    }

    /** Stamp `$n` and every descendant; answer the union of the subtree. */
    private function walk(Node $n): int
    {
        $e = $this->intrinsic($n);
        $n->effects = $e;
        $agg = $e;
        foreach (Walk::children($n) as $c) { $agg = $agg | $this->walk($c); }
        return $agg;
    }

    private function intrinsic(Node $n): int
    {
        $k = $n->kind;

        if ($k === Node::KIND_CONCAT)    { return Effects::ALLOC; }
        if ($k === Node::KIND_ARRAY_LIT) { return Effects::ALLOC; }
        if ($k === Node::KIND_CLOSURE)   { return Effects::ALLOC; }

        if ($k === Node::KIND_CAST) {
            $t = $this->castTarget($n);
            if ($t === 'string' || $t === 'array' || $t === 'object') {
                return Effects::ALLOC;
            }
            return Effects::NONE;
        }

        if ($k === Node::KIND_NEW_OBJ) {
            return Effects::ALLOC | Effects::MAY_THROW;
        }

        if ($k === Node::KIND_DIV || $k === Node::KIND_MOD) {
            return Effects::MAY_THROW;
        }

        if ($k === Node::KIND_THROW) {
            return Effects::ESCAPE | Effects::MAY_THROW;
        }

        if ($k === Node::KIND_CALL || $k === Node::KIND_STATIC_CALL) {
            return Effects::MAY_THROW;
        }
        if ($k === Node::KIND_METHOD_CALL || $k === Node::KIND_INVOKE) {
            return Effects::MAY_THROW | Effects::CALL_UNKNOWN;
        }

        if ($k === Node::KIND_STORE_PROPERTY
            || $k === Node::KIND_STORE_ELEMENT
            || $k === Node::KIND_STORE_STATIC_PROP) {
            return Effects::ESCAPE | Effects::STORE_HEAP;
        }
        if ($k === Node::KIND_STORE_DYN_PROP) {
            // Writing an unseen dynamic key may grow the property bag.
            return Effects::ALLOC | Effects::ESCAPE | Effects::STORE_HEAP;
        }

        if ($k === Node::KIND_RETURN) {
            return $this->returnValue($n) === null ? Effects::NONE : Effects::ESCAPE;
        }

        return Effects::NONE;
    }

    private function castTarget(\Compile\Mir\Cast $n): string { return $n->target; }
    private function returnValue(\Compile\Mir\Return_ $n): ?Node { return $n->value; }
}
