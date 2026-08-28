<?php

namespace Manticore;

/**
 * Compiler-side lifetime coordinator.
 *
 * This is deliberately not the target-value allocator: it never owns a PHP
 * value, changes a MemoryAbi tag, or resets the emitted runtime arena. It marks
 * boundaries at which compiler scratch owners have already been detached and
 * then gives the host collector/allocator a chance to reclaim cycles and free
 * lists. The boundary is opt-in because a full Bacon-Rajan scan is measurable
 * work on small builds and cannot reclaim arena-backed objects.
 *
 * MANTICORE_PHASE_RECLAIM=1 enables it. `tools/selfhost.sh` enables it for the
 * compiler rebuild process only; a later target build chooses its own memory
 * mode independently.
 */
final class Allocator
{
    private static ?bool $enabled = null;
    private static int $epoch = 0;

    private static function enabled(): bool
    {
        if (self::$enabled !== null) { return self::$enabled; }
        $v = \getenv('MANTICORE_PHASE_RECLAIM');
        self::$enabled = $v !== false && $v !== '' && $v !== '0';
        return self::$enabled;
    }

    /** Release detached compiler scratch at a named phase boundary. */
    public static function release(string $phase): void
    {
        if (!self::enabled()) { return; }
        self::$epoch = self::$epoch + 1;
        $cycles = 0;
        $caches = 0;
        if (\function_exists('gc_collect_cycles')) {
            $cycles = (int)\gc_collect_cycles();
        }
        if (\function_exists('gc_mem_caches')) {
            $caches = (int)\gc_mem_caches();
        }
        if (\Compile\Stats::$on) {
            \Compile\Stats::bump('memory.phase.releases', 1);
            \Compile\Stats::bump('memory.phase.cycles', $cycles);
            \Compile\Stats::bump('memory.phase.cache_bytes', $caches);
            \Compile\Stats::line('memory phase ' . $phase . ' release=' . (string)self::$epoch
                . ' cycles=' . (string)$cycles . ' cache_bytes=' . (string)$caches);
        }
    }
}
