<?php

namespace Compile\Mir;

/**
 * Arena-allocation state for the function being emitted.
 *
 * Two jobs. First, which locals hold arena-allocated vecs — their `$x[]=`
 * appends must go through `@__mir_arena_realloc` rather than the heap path.
 * Second, the loop arena-reset analysis: a loop whose body bump-allocates may
 * reset the arena each iteration, but only if nothing it allocated outlives the
 * iteration ({@see $hasAlloc}, {@see $bindsNonLocal}, {@see $boundLocals} are the
 * scan's verdict inputs).
 *
 * One instance per {@see EmitLlvm::emit()}.
 */
final class ArenaContext
{
    /** Set by emitArrayLit when it bump-allocated a vec, read by the enclosing
     *  emitStoreLocal to mark the target as an arena vec. */
    public bool $vecAllocated = false;
    /** @var array<string, bool> locals holding an arena-allocated vec — their
     *  `$x[]=` appends must use @__mir_arena_realloc. */
    public array $vecLocals = [];

    // ── loop arena-reset scan (arenaScan fills these; the verdict reads them) ──

    /** The loop subtree contains an Arena allocation. */
    public bool $hasAlloc = false;
    /** An Arena value is bound to a NON-local sink (property / element / static /
     *  dyn prop) — always unsafe to reset, the value outlives the frame. */
    public bool $bindsNonLocal = false;
    /** @var array<string, bool> locals a store binds an Arena value to in the
     *  loop — each must pass the reset-liveness check (written-before-read each
     *  iteration AND not read outside the loop). */
    public array $boundLocals = [];

    /** SSA regs threaded from the arena position save to its restore. */
    public string $saveCurReg = '';
    public string $saveUsedReg = '';

    /** Restart the loop-reset scan. */
    public function resetScan(): void
    {
        $this->hasAlloc = false;
        $this->bindsNonLocal = false;
        $this->boundLocals = [];
    }
}
