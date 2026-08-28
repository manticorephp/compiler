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
 *                                         Empty ⇒ `hybrid`, resolved in the driver.
 *   MANTICORE_ARENA_ARRAYS=0            — opt OUT of arena-allocating eligible
 *                                         non-escaping arrays. ON by default.
 *   MANTICORE_EMPTY_SINGLETON=0         — opt OUT of the shared immortal empty
 *                                         `[]` buffer. ON by default.
 *   MANTICORE_POOL=1                    — opt IN to the small-object pool
 *                                         allocator. OFF by default after the
 *                                         arm64 reserved-address crash; must be
 *                                         the same for the whole build (see $pool).
 *   MANTICORE_REF_CELLS=0               — opt OUT of reference cells. ON by
 *                                         default; must be the same for the
 *                                         whole build (see $refCells).
 *   MANTICORE_PROFILE=1                 — emit thread-local rc/alloc counters +
 *                                         an atexit tally (memory-traffic profile).
 *   MANTICORE_ALLOC_TRACE=1              — emit allocation/reclaim balance
 *                                         counters and an atexit tally; this is
 *                                         statistics only and does not log each
 *                                         allocation.
 *   MANTICORE_DEBUG_VERIFY=1            — slow-path invariant checks at memory ops
 *                                         (abort on failure); bisects rc-balance bugs.
 *   MANTICORE_REFLECT_REPORT=1          — report which classes reflection kept alive.
 *   MANTICORE_FRAME_POINTERS=1          — give every emitted function a frame
 *                                         record, so a profiler can name the
 *                                         CALLER and not just the leaf.
 *
 * The two array flags are ON by default; every other switch is off.
 * `MANTICORE_TYPECHECK=1` gates the TypeCheck pass and is read in the driver,
 * not here. User-facing documentation: `docs/memory.md`.
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
     * Low-overhead allocator ownership telemetry. Unlike `$profile`, this flag
     * is dedicated to allocator/reclaim balance counters and is opt-in via
     * `MANTICORE_ALLOC_TRACE=1`.
     */
    public static bool $allocTrace = false;

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

    /**
     * NOT the effective default. The driver always resolves the mode through
     * {@see \Compile\Mir\MemoryMode::resolve()}, which answers `hybrid` for an
     * empty selector, and calls {@see applyMemoryMode()} before any pass runs.
     * This initialiser only covers a host that constructs passes directly.
     */
    public static string $memoryMode = self::MEM_RC;

    /**
     * Arena allocation for non-escaping UNIFIED ARRAYS. When set, an array
     * literal / array-producing builtin whose value provably does not escape
     * its frame bump-allocates in the arena (tag {@see \Compile\MemoryAbi::
     * ARRAY_TAG_ARENA}) and is bulk-freed at scope exit — no malloc/rc/free.
     * Mirrors the arena path strings already take. Off ⇒ every array is
     * malloc+rc. DEFAULT ON (gated green: self-hosts, fixpoint byte-identical,
     * suite + difftest clean). Disable with `MANTICORE_ARENA_ARRAYS=0`.
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
     * gated green: fixpoint byte-identical, suite + difftest clean). Disable with
     * `MANTICORE_EMPTY_SINGLETON=0`.
     */
    public static bool $emptyArraySingleton = true;

    /**
     * Size-classed small-object pool in front of libc for objects, unified
     * array buffers and hash bucket side-arrays (strings already had their own
     * two-class free list). See {@see \Compile\MemoryAbi::POOL_GRAIN} for the
     * shape. DEFAULT OFF: the mmap-backed arm64 pool hit a reserved-address
     * SIGBUS during the large Doctrine self-host target. Enable explicitly with
     * `MANTICORE_POOL=1` only for a controlled pool experiment.
     *
     * ⚠ The flag must hold for the WHOLE build. `__mir_pool_alloc` / `_free`
     * and the allocators calling them are `linkonce_odr`: link a stdlib `.o`
     * built with the pool against a user `.o` built without it and the linker
     * keeps one body of each, so a block can be pooled by one and handed to
     * libc `free()` by the other. `bin/compile` / `bin/build` pass their
     * environment to both halves, so exporting the variable for the build is
     * enough — flipping it for a single file is not.
     */
    public static bool $pool = false;

    /**
     * Reference cells — a `&` whose result is a storable VALUE rather than an
     * address ({@see docs/design/reference-cells.md}). DEFAULT ON. Disable with
     * `MANTICORE_REF_CELLS=0`.
     *
     * ⚠ Same whole-build rule as {@see $pool}, and for the same reason: the REF
     * arms live in `@__manticore_tag` / `@__mir_cell_drop`, which
     * {@see \Compile\Mir\Passes\EmitLlvm::linkonceRuntime} makes `linkonce_odr`.
     * Link a stdlib `.o` built without them against a user `.o` built with them
     * and the linker keeps ONE body — so half the program would deref a ref cell
     * and the other half would read the box pointer as a value.
     *
     * This exists to prove a test is not inert: with it off, every construct the
     * ref-cell epic added must refuse or fail, never silently pass.
     */
    public static bool $refCells = true;

    /**
     * Compile-time profile: per-pass wall time, module size after each pass,
     * per-round lines from the fixpoint passes, and a tail of whole-program
     * scan counters. `MANTICORE_STATS=1`. See {@see \Compile\Stats}.
     */
    public static bool $stats = false;

    /**
     * Splice `"frame-pointer"="all"` into every emitted function, so a host
     * profiler can unwind out of a leaf and name its PHP caller. Costs a
     * prologue in the profiled binary — it is a MEASUREMENT build, never the
     * shipped one. `MANTICORE_FRAME_POINTERS=1`; see `with_frame_pointers()` in
     * `src/Manticore/Main.php` for why the driver flag cannot do this and the
     * attribute has to be spliced into the IR text.
     */
    public static bool $framePointers = false;

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
        $env = \getenv('MANTICORE_ALLOC_TRACE');
        if ($env !== false && $env !== '0' && $env !== '') {
            self::$allocTrace = true;
        }
        $env = \getenv('MANTICORE_REFLECT_REPORT');
        if ($env !== false && $env !== '0' && $env !== '') {
            self::$reflectReport = true;
        }
        $env = \getenv('MANTICORE_FRAME_POINTERS');
        if ($env !== false && $env !== '0' && $env !== '') {
            self::$framePointers = true;
        }
        $env = \getenv('MANTICORE_STATS');
        if ($env !== false && $env !== '0' && $env !== '') {
            self::$stats = true;
            Stats::$on = true;
            Stats::init();
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
        $env = \getenv('MANTICORE_POOL');
        if ($env === '0') {
            self::$pool = false;
        } elseif ($env === '1') {
            self::$pool = true;
        }
        $env = \getenv('MANTICORE_REF_CELLS');
        if ($env === '0') {
            self::$refCells = false;
        } elseif ($env !== false && $env !== '') {
            self::$refCells = true;
        }
    }
}
