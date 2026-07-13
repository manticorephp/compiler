<?php

namespace Compile\Mir;

/**
 * The function EmitLlvm is currently emitting: its identity, ABI and the
 * owned locals its every `ret` must clean up.
 *
 * Reset per function ({@see reset}); one instance per {@see EmitLlvm::emit()}.
 */
final class FunctionEmitFrame
{
    /** Name of the function being emitted (property-hook self-ref guard). */
    public string $name = '';
    /** Body of the function being emitted — the loop arena-reset liveness
     *  check needs it to see uses OUTSIDE the loop. */
    public ?Node $body = null;
    /** Declared return type (cell → box on the way out). */
    public ?Type $returnType = null;
    /** The fn returns by-ref: `emitReturn` yields an address, not a value. */
    public bool $returnsByRef = false;
    /** The fn is a closure — uniform ABI: scalar params/returns travel as
     *  tagged cells. */
    public bool $isClosure = false;
    /** The fn opened an arena scope: every `ret` must `@__mir_arena_leave` first. */
    public bool $hasArena = false;
    /** @var array<string, bool> param names — transfer skips params, which are
     *  retained-on-entry by initRcObjSlots (suppressing their release would
     *  unbalance that entry retain). */
    public array $paramNames = [];
    /** @var array<string, MemoryOp_> owned RcHeap obj/vec/str locals → their
     *  rc_release MemoryOp node (the flavor is re-derived per use via
     *  rcReleaseFlavor; storing the flavor string here corrupts under the
     *  self-host backend). Released before every `ret` except the returned one
     *  (transfer); slots null-inited. */
    public array $rcObjLocals = [];
    /** @var array<string, bool> vec locals mutated in this fn (append / element
     *  store) — drive copy-on-assign value semantics. */
    public array $mutatedVecLocals = [];
    /** @var array<string, bool> owned rcObj locals whose value flows into a
     *  BORROWING container store (a vec/assoc/property/array-lit store that does
     *  NOT retain it — erased element type, no usable fallback). Ownership
     *  transfers to the container, so the local's scope-exit / pre-return /
     *  reassign release is SUPPRESSED. This is B2 escape-driven ownership: it
     *  kills the over-release UAF (the enum/arena heisenbug) by moving instead of
     *  adding a retain (adding retains pushed the binary toward the corruption
     *  boundary). Worst case is a leak (the safe direction), never a double-free. */
    public array $transferredLocals = [];
    /** @var array<string, bool> owned vec/assoc locals whose BUFFER is shared
     *  with an outliving owner: passed as a (by-value) call argument, so the
     *  callee co-owns the buffer AND its retained element refs (the +1 each
     *  `array_append` adds). Their scope-exit release must drop the BUFFER ONLY
     *  (plain `array_release`), never element-drop: `array_release_obj/_str`
     *  walks and -1's every element, which on a co-owned buffer double-frees the
     *  shared elements. Element-drop stays valid only for a SOLE-owner confined
     *  vec (built and discarded, never shared). */
    public array $elementSharedLocals = [];
}
