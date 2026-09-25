<?php

/**
 * PHP `gc_*` family. The surrounding refcount layer reclaims every
 * non-cyclic graph on `rc == 0`; cycles are reclaimed by the
 * Bacon-Rajan collector, which `gc_collect_cycles()` drives (see
 * `docs/design/memory-abi.md` §7). Collection is manual-trigger only
 * and does not scan static/global roots — `docs/ROADMAP.md`.
 *
 * Every function here except `gc_collect_cycles` is a genuine no-op in
 * AOT mode — there is no runtime-tunable collector to toggle. The
 * presence of these symbols keeps user PHP that calls them
 * compiling.
 */

function gc_enabled(): bool
{
    return true;
}

function gc_disable(): void
{
}

function gc_enable(): void
{
}

/**
 * Shadowed by a compiler builtin (see EmitLlvm::emitBuiltin →
 * `@__manticore_cc_collect_cycles`): the AOT path runs the real Bacon-Rajan
 * collector and returns the freed-object count. This body is the
 * interpreter/fallback stub only.
 */
function gc_collect_cycles(): int
{
    return 0;
}

function gc_mem_caches(): int
{
    return 0;
}

/**
 * Bootstrap twins of the weak-reference codegen builtins (prelude/weak.php);
 * a compiler that knows the builtins never calls these. Arming is a no-op, so
 * the free path never reports a death and the twin of the address→object
 * step is never reached.
 */
function __mc_weak_arm(): void
{
}

function __mc_obj_from_addr(int $addr): mixed
{
    throw new \Error('WeakMap/WeakReference need a compiler that knows __mc_obj_from_addr');
}
