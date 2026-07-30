<?php

namespace Compile\Runtime;

use Codegen\Llvm\Block;
use Codegen\Llvm\FunctionDef;
use Codegen\Llvm\Value;

/**
 * Environment contract for the standalone runtime emitters
 * ({@see UnifiedArrayRuntime}). It abstracts the two things a
 * hand-emitted runtime helper needs from its backend:
 *
 *   1. **Allocation** — `rtAlloc` / `rtRealloc` / `rtArenaBypass`.
 *   2. **Instrumentation + labels** — `rtFreshLabel`,
 *      `rtDebugDprintf`, `rtProfileBump`.
 *
 * One implementation today: {@see BareHost} (libc malloc, no
 * instrumentation). The seam predates the removal of the AST backend,
 * which was the second host; it is kept because the runtime emitters
 * are backend-agnostic by construction and cheap to keep that way.
 *
 * This is the "AllocStrategy" seam — folded into one host because
 * the helper bodies interleave allocation with trace/profile calls;
 * a single injection point is cheaper than two indirections.
 */
interface RuntimeHost
{
    /**
     * Allocate `$size` bytes for the given refcount flavor
     * ({@see \Compile\MemoryOp}::FLAVOR_*). Returns the ptr Value.
     */
    public function rtAlloc(Block $b, Value $size, string $flavor): Value;

    /**
     * Reallocate. libc: `realloc(oldPtr, newSize)`; arena:
     * alloc-fresh + memcpy. `$oldSize` is required (libc ignores it).
     */
    public function rtRealloc(Block $b, Value $oldPtr, Value $oldSize, Value $newSize): Value;

    /**
     * Arena-mode early bypass guard for an rc helper body: when the
     * incoming ptr lives in the arena, return `$ret` immediately.
     * No-op (returns `$entry`) outside hybrid mode. Returns the block
     * to continue emitting in.
     */
    public function rtArenaBypass(FunctionDef $fn, Block $entry, Value $ptr, ?Value $ret): Block;

    /** Allocate a unique label suffix shared with the host's counter. */
    public function rtFreshLabel(string $hint): string;

    /**
     * Emit a `dprintf(2, fmt, args...)` rc-trace line. No-op unless
     * the host has rc-trace enabled.
     *
     * @param array<int, Value> $args
     */
    public function rtDebugDprintf(Block $b, string $fmt, array $args): void;

    /** Bump a profile counter global. No-op unless profiling is on. */
    public function rtProfileBump(Block $b, string $counter): void;
}
