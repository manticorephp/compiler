<?php

namespace Compile;

/**
 * Compile-time switches for the Manticore compiler. Each flag is read once
 * from an environment variable when the compiler starts.
 *
 * Two layers of "compile-time": when bin/manticore compiles a user file these
 * control what it emits into the user's binary; when bin/compile bootstraps
 * bin/manticore (Zend runs the same code) they control bin/manticore's OWN
 * binary — so it is self-debuggable without extra plumbing.
 *
 * Env vars:
 *   MANTICORE_MEMORY=<rc|arena|hybrid>  — allocation strategy (also `--memory`).
 *   MANTICORE_PROFILE=1                 — emit thread-local rc/alloc counters +
 *                                         an atexit tally (memory-traffic profile).
 *   MANTICORE_DEBUG_VERIFY=1            — slow-path invariant checks at memory ops
 *                                         (abort on failure); bisects rc-balance bugs.
 *
 * All off by default.
 */
final class Debug
{
    /**
     * Emit slow-path invariant checks at every memory op. On failure write a
     * diagnostic to stderr and call `abort()`. Used to bisect rc-balance bugs
     * (canonical: locate the site that releases a tagged scalar or stack ptr).
     */
    public static bool $verify = false;

    /**
     * Report which classes carry reflection metadata, and why the set is what
     * it is. Reflection trades binary size for a runtime answer, and the gate
     * that decides it fails OPEN — one unresolvable class name silently puts
     * every class back in. Without a way to look, that reads as "reflection is
     * just expensive" rather than "one call site escaped".
     * `MANTICORE_REFLECT_REPORT=1`.
     */
    public static bool $reflectReport = false;

    /**
     * PGO-style metrics. When set, the emitted binary carries thread-local
     * counters incremented at every assoc / obj retain / release / alloc; an
     * `atexit`-registered dump prints the tally to stderr at exit. Answers
     * "how much refcount work does bin/manticore do to compile its own source?"
     */
    public static bool $profile = false;

    /**
     * Memory mode selector:
     *   - `hybrid` — escape-analysis decides per-allocation (default)
     *   - `rc`     — every alloc through libc + refcount/CC
     *   - `arena`  — process-wide bump pointer, refcount ops elided
     *
     * Set via CLI `--memory=<mode>` or env var `MANTICORE_MEMORY`.
     */
    public const MEM_RC     = 'rc';
    public const MEM_ARENA  = 'arena';
    public const MEM_HYBRID = 'hybrid';

    public static string $memoryMode = self::MEM_RC;

    /**
     * Arena allocation for non-escaping UNIFIED ARRAYS. When set, an array
     * literal / array-producing builtin whose value provably does not escape
     * its frame bump-allocates in the arena (tag {@see \Compile\MemoryAbi::
     * ARRAY_TAG_ARENA}) and is bulk-freed at scope exit — no malloc/rc/free.
     * Mirrors the arena path strings already take. Off ⇒ every array is
     * malloc+rc. DEFAULT ON (proven: self-hosts, fixpoint byte-identical,
     * 363/363, difftest 354/0/0). Disable with `MANTICORE_ARENA_ARRAYS=0`.
     * First cut: only FLAT int/float/bool int-keyed arrays go arena; nested /
     * string-value / string-key / object arrays stay rc-heap (see
     * InferAllocKind::isArenaScalarArray).
     */
    public static bool $arenaArrays = true;

    /**
     * Names of functions / methods that carry `#[Arena]` (per-function arena
     * hint). Reserved hook — not yet populated; consumed by codegen once the
     * per-scope memory control lands. Method keys use `ClassName::methodName`.
     *
     * @var array<string, true>
     */
    public static array $arenaScopedFns = [];

    /**
     * Route every non-arena empty `[]` literal to ONE immortal `linkonce_odr`
     * singleton instead of a fresh `__mir_array_alloc(0)`. The singleton carries
     * a saturated refcount (`1 << 62`) so COW always clones on the first mutation
     * and release never frees it. In-place mutators (promote / grow / unshift)
     * DON'T check rc — they would free/realloc the static singleton and abort in
     * libmalloc — so {@see \Compile\Runtime\UnifiedArrayRuntime::emitDeimmortal}
     * swaps it for a fresh rc=1 empty at the entry of set_int / set_str / unshift.
     * DEFAULT ON (measured: kills 60.3% of ALL array mallocs during self-build;
     * gated: fixpoint byte-identical, AOT 514/0, difftest 500/0). Disable with
     * `MANTICORE_EMPTY_SINGLETON=0`.
     */
    public static bool $emptyArraySingleton = true;

    public static function applyMemoryMode(string $mode): bool
    {
        if ($mode === self::MEM_RC || $mode === self::MEM_ARENA || $mode === self::MEM_HYBRID) {
            self::$memoryMode = $mode;
            return true;
        }
        return false;
    }

    /** Read env vars once into the static flags. Called at compiler startup. */
    public static function initFromEnvironment(): void
    {
        $env = \getenv('MANTICORE_DEBUG_VERIFY');
        if ($env !== false && $env !== '0' && $env !== '') {
            self::$verify = true;
        }
        $env = \getenv('MANTICORE_PROFILE');
        if ($env !== false && $env !== '0' && $env !== '') {
            self::$profile = true;
        }
        $env = \getenv('MANTICORE_REFLECT_REPORT');
        if ($env !== false && $env !== '0' && $env !== '') {
            self::$reflectReport = true;
        }
        $env = \getenv('MANTICORE_MEMORY');
        if ($env !== false && $env !== '') {
            self::applyMemoryMode($env);
        }
        $env = \getenv('MANTICORE_ARENA_ARRAYS');
        if ($env === '0') {
            self::$arenaArrays = false;
        } elseif ($env !== false && $env !== '') {
            self::$arenaArrays = true;
        }
        $env = \getenv('MANTICORE_EMPTY_SINGLETON');
        if ($env === '0') {
            self::$emptyArraySingleton = false;
        } elseif ($env !== false && $env !== '') {
            self::$emptyArraySingleton = true;
        }
    }
}
