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
}
