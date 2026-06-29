<?php

/**
 * PHP `gc_*` family. Stubs only today — the surrounding refcount
 * layer reclaims every non-cyclic graph on `rc == 0`; cycles leak
 * until the Bacon-Rajan collector lands (see
 * `docs/bootstrap/11-cycle-collector-design.md` for the design and
 * `docs/ROADMAP.md` for the dependency
 * order).
 *
 * `gc_enable` / `gc_disable` / `gc_mem_caches` are no-ops in AOT
 * mode — there is no runtime-tunable collector to toggle. The
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
