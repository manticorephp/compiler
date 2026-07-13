<?php

namespace Compile\Mir;

/**
 * Where each local of the function being emitted lives.
 *
 * The common case is an `alloca` slot ({@see $slots}). Three kinds of local are
 * indirected instead:
 *  - a by-ref param ({@see $refLocals}) holds the CALLER's address — loads and
 *    stores deref it;
 *  - a static local, or a `global $x` name in `__main`, is backed by a module
 *    global cell ({@see $globalBacked}) so its value survives the frame;
 *  - a local captured by-ref by a closure ({@see $byRefCaptured}) is heap-boxed
 *    so the closure and the frame see the same cell.
 *
 * One instance per {@see EmitLlvm::emit()}; refilled per function.
 */
final class LocalSlots
{
    /** @var array<string, string> local name → alloca SSA id */
    public array $slots = [];
    /** @var array<string, true> by-ref param names in the current fn */
    public array $refLocals = [];
    /** @var array<string, string> static-local / `global $x` name → global cell */
    public array $globalBacked = [];
    /** @var array<string, true> locals captured by-ref by a closure (heap-boxed) */
    public array $byRefCaptured = [];
}
