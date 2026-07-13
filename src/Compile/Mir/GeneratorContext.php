<?php

namespace Compile\Mir;

/**
 * Per-function generator emit state, live only while EmitLlvm emits a
 * generator's `$resume` body. Outside a generator, {@see $inGenerator} is
 * false and the pointer fields are empty.
 *
 * A single instance is created per {@see EmitLlvm::emit()}; the generator
 * emit path assigns the frame-word pointers on entry to each resume body.
 */
final class GeneratorContext
{
    /** True while emitting a generator resume body. */
    public bool $inGenerator = false;
    /** Running yield index (1..N) — each yield's suspend/resume state number. */
    public int $yieldCounter = 0;
    /** SSA ptr to the frame's `state` word (entry GEP, dominates all). */
    public string $statePtr = '';
    /** SSA ptr to the frame's `current` word (entry GEP). */
    public string $currentPtr = '';
    /** SSA ptr to the frame's `key` word (yielded key). */
    public string $keyPtr = '';
    /** SSA ptr to the frame's `nextkey` word (auto-increment key counter). */
    public string $nextKeyPtr = '';
    /** SSA ptr to the frame's `sent` word (value passed in via send()). */
    public string $sentPtr = '';
    /** SSA ptr to the frame's `retval` word (return value for getReturn()). */
    public string $retvalPtr = '';
    /** Module uses `$gen->throw($e)` → emit the per-yield injection check. */
    public bool $throwUsed = false;
}
