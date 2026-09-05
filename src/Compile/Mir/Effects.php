<?php

namespace Compile\Mir;

/**
 * Memory-effect set carried by every MIR node (and, aggregated, by
 * every {@see FunctionDef}). Computed by {@see Passes\InferEffects}
 * after type inference; consumed by {@see Passes\InferAllocKind} and
 * {@see Passes\InsertMemoryOps} to decide retain / release / arena
 * placement — so that EmitLlvm never invents memory ops from feature
 * handlers.
 *
 * A BITMASK, not an object: one per MIR node meant 2.0 M objects
 * allocated and none ever freed on a symfony-sized build (the census in
 * `docs/status/T6-MEMORY-HANDOFF-2026-09-04.md`), all of it to carry
 * seven booleans. The class survives as the vocabulary — constants plus
 * the spelling — and `Node::$effects` / `FunctionDef::$effects` are ints.
 *
 * The vocabulary (contract step #3):
 *  - ALLOC        op allocates a fresh heap value (string concat, array
 *                 / object literal, `new`, closure, (string)/(array)/(object) cast)
 *  - RETAIN       a refcount is incremented here   (reserved — filled by MemoryOps)
 *  - RELEASE      a refcount is decremented here   (reserved — filled by MemoryOps)
 *  - ESCAPE       a value outlives the current frame (return, throw, store-to-heap)
 *  - MAY_THROW    op may unwind (div/mod by zero, any call, `new`, `throw`)
 *  - CALL_UNKNOWN dispatches through a callee whose body we can't see
 *                 (virtual method, `$f(...)` invoke)
 *  - STORE_HEAP   writes a value into a heap slot (property / element /
 *                 static-prop / dynamic-prop store)
 *
 * RETAIN / RELEASE stay clear at inference time; they are the MemoryOps
 * pass's output, kept in the vocabulary so the type is the single home
 * for the whole effect lattice.
 */
final class Effects
{
    public const NONE         = 0;
    public const ALLOC        = 1;
    public const RETAIN       = 2;
    public const RELEASE      = 4;
    public const ESCAPE       = 8;
    public const MAY_THROW    = 16;
    public const CALL_UNKNOWN = 32;
    public const STORE_HEAP   = 64;

    /**
     * Stable comma-joined spelling in vocabulary order. Empty string
     * for the empty set. Built by hand (no `implode`) to stay on the
     * self-host stdlib surface.
     */
    public static function toString(int $m): string
    {
        $out = '';
        $out = self::append($out, $m & self::ALLOC, 'alloc');
        $out = self::append($out, $m & self::RETAIN, 'retain');
        $out = self::append($out, $m & self::RELEASE, 'release');
        $out = self::append($out, $m & self::ESCAPE, 'escape');
        $out = self::append($out, $m & self::MAY_THROW, 'throw');
        $out = self::append($out, $m & self::CALL_UNKNOWN, 'callUnknown');
        $out = self::append($out, $m & self::STORE_HEAP, 'storeHeap');
        return $out;
    }

    private static function append(string $acc, int $on, string $name): string
    {
        if ($on === 0) { return $acc; }
        if ($acc === '') { return $name; }
        return $acc . ',' . $name;
    }
}
