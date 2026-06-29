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
     * Names of functions / methods that carry `#[Arena]` (per-function arena
     * hint). Reserved hook — not yet populated; consumed by codegen once the
     * per-scope memory control lands. Method keys use `ClassName::methodName`.
     *
     * @var array<string, true>
     */
    public static array $arenaScopedFns = [];

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
        $env = \getenv('MANTICORE_MEMORY');
        if ($env !== false && $env !== '') {
            self::applyMemoryMode($env);
        }
    }
}
