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
 * intrinsic {@see Effects} and stores the per-function union on the
 * {@see FunctionDef}. Pure analysis — never rewrites the tree.
 *
 * "Intrinsic" = what *this* op does, not its subtree. The function
 * aggregate is the union of every node's intrinsic set, which is what
 * a call-graph summary pass (#4) and the MemoryOps lowering (#5) read.
 *
 * Effect rules (v1, deliberately conservative — precision improves
 * when callee summaries land):
 *  - alloc:       concat, array-lit, `new`, closure, (string|array|object)
 *                 cast, dynamic-prop store (bag may grow)
 *  - throw:       div, mod, `new`, every call form, `throw`
 *  - callUnknown: virtual method call, `$f(...)` invoke
 *  - storeHeap:   property / element / static-prop / dynamic-prop store
 *  - escape:      non-void return, `throw`, every heap store
 *  - retain/release: never set here — owned by MemoryOps (#5)
 */
final class InferEffects implements Pass
{
    public const NAME = 'infer-effects';

    public function name(): string { return self::NAME; }

    public function requires(): array { return [InferTypes::NAME]; }

    public function run(Module $module): Module
    {
        foreach ($module->functions as $fn) {
            $agg = new Effects();
            $this->walk($fn->body, $agg);
            $fn->effects = $agg;
        }
        $module->markPassApplied(self::NAME);
        return $module;
    }

    /** Stamp `$n` and every descendant, unioning intrinsics into `$agg`. */
    private function walk(Node $n, Effects $agg): void
    {
        $e = $this->intrinsic($n);
        $n->effects = $e;
        $agg->mergeFrom($e);
        foreach (Walk::children($n) as $c) { $this->walk($c, $agg); }
    }

    private function intrinsic(Node $n): Effects
    {
        $k = $n->kind;

        if ($k === Node::KIND_CONCAT)    { return new Effects(alloc: true); }
        if ($k === Node::KIND_ARRAY_LIT) { return new Effects(alloc: true); }
        if ($k === Node::KIND_CLOSURE)   { return new Effects(alloc: true); }

        if ($k === Node::KIND_CAST) {
            $t = $this->castTarget($n);
            if ($t === 'string' || $t === 'array' || $t === 'object') {
                return new Effects(alloc: true);
            }
            return new Effects();
        }

        if ($k === Node::KIND_NEW_OBJ) {
            return new Effects(alloc: true, throw: true);
        }

        if ($k === Node::KIND_DIV || $k === Node::KIND_MOD) {
            return new Effects(throw: true);
        }

        if ($k === Node::KIND_THROW) {
            return new Effects(escape: true, throw: true);
        }

        if ($k === Node::KIND_CALL || $k === Node::KIND_STATIC_CALL) {
            return new Effects(throw: true);
        }
        if ($k === Node::KIND_METHOD_CALL || $k === Node::KIND_INVOKE) {
            return new Effects(throw: true, callUnknown: true);
        }

        if ($k === Node::KIND_STORE_PROPERTY
            || $k === Node::KIND_STORE_ELEMENT
            || $k === Node::KIND_STORE_STATIC_PROP) {
            return new Effects(escape: true, storeHeap: true);
        }
        if ($k === Node::KIND_STORE_DYN_PROP) {
            // Writing an unseen dynamic key may grow the property bag.
            return new Effects(alloc: true, escape: true, storeHeap: true);
        }

        if ($k === Node::KIND_RETURN) {
            return $this->returnValue($n) === null
                ? new Effects()
                : new Effects(escape: true);
        }

        return new Effects();
    }

    private function castTarget(Node $n): string { return $this->asCast($n)->target; }
    private function returnValue(Node $n): ?Node { return $this->asReturn($n)->value; }

    private function asCast(Node $n): \Compile\Mir\Cast { return $n; }
    private function asReturn(Node $n): \Compile\Mir\Return_ { return $n; }
}
