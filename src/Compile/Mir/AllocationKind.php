<?php

namespace Compile\Mir;

/**
 * Where an allocating MIR node's value lives, and how it is reclaimed
 * (contract step #4). Assigned by {@see Passes\InferAllocKind} from
 * escape analysis — never by a global flag in EmitLlvm. Consumed by
 * the future MemoryOps lowering (#5) to pick retain/release vs
 * scope-exit free vs nothing.
 *
 * Verdict lattice (the analysis decides the first two; the rest are
 * vocabulary the mode overlay / future passes fill):
 *  - RcHeap     value escapes the frame → reference-counted heap
 *  - NoRefcount value is frame-confined → freed at scope exit, no RC
 *  - Arena      mode overlay (`--memory=arena` / `#[Arena]`): bump-alloc,
 *               freed when the arena scope ends
 *  - Borrowed   value is owned elsewhere (alias of a param / caller
 *               value) — neither retained nor released here
 *  - Static     global/constant lifetime — never freed
 *
 * Soundness rule: the analysis defaults to RcHeap and only downgrades
 * to NoRefcount when it can *prove* non-escape. Over-marking RcHeap is
 * safe (just slower); under-marking would be a use-after-free once #5
 * acts on the verdict.
 */
final class AllocationKind
{
    public const RC_HEAP     = 'RcHeap';
    public const NO_REFCOUNT = 'NoRefcount';
    public const ARENA       = 'Arena';
    public const BORROWED    = 'Borrowed';
    public const STATIC_     = 'Static';
}
