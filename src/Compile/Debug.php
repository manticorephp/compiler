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
 *   MANTICORE_ROOT_TRACE=1               — emit aggregate compiler root/cache
 *                                         snapshots at phase and emission-batch
 *                                         boundaries; diagnostic only.
 *   MANTICORE_COMPACT_CACHES=1           — clear pure emission memoization
 *                                         tables at bounded batch boundaries;
 *                                         compiler-only retention control.
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
     * Aggregate compiler-root/cache telemetry. This never walks target values;
     * it only counts existing compiler-owned tables and is opt-in via
     * `MANTICORE_ROOT_TRACE=1`.
     */
    public static bool $rootTrace = false;

    /**
     * Bound pure emitter memoization tables at batch boundaries. These tables
     * are performance caches; clearing them changes no emitted semantics.
     */
    public static bool $compactCaches = false;

    /**
     * ON by default; `MANTICORE_RC_RETURN_OWNS=0` opts out. Stop vetoing a property slot's
     * release-before-overwrite because the slot is READ by a `return`.
     *
     * The veto exists because a property read normally hands out a raw borrow,
     * and dropping the old value would strand it. A RETURN is not that case:
     * `EmitLlvmModule::emitReturn` already gates on `isBorrowedObjReturn` and
     * retains a borrowed property read before handing it back, so the caller
     * owns a reference of its own. This is `tools/prof/propleak.php`'s stated
     * ordering — a property READ must own what it reads BEFORE a property WRITE
     * may drop it — already satisfied in this one position.
     *
     * Landed with `tests/aot/cases/prop_overwrite_destruct_getter.php` (which fails
     * without it), a flat `tools/prof/rcbalance.php` table, and a green 1026/1028
     * suite. The opt-out stays so a suspected double-free can be bisected in one
     * run — that failure is invisible under Zend, invisible at -O0, and often
     * invisible until gen-2.
     */
    public static bool $rcReturnOwns = true;

    /**
     * ON by default; `MANTICORE_RC_RECV_TEMP=0` opts out. Release the RECEIVER temp of a method call.
     *
     * `f()->m()` evaluates `f()` into a temp, uses it as `$this`, and then drops
     * it on the floor. Argument position has released its fresh temps since
     * forever (freshRcArgFlavor + rcReleaseReg); receiver position never did, so
     * every `$this->getFoo()->bar()` leaked one object per execution. The same
     * temp is what a condition leaks — `if (f()->ok())` is a receiver too.
     *
     * Safe against a fluent `return $this`: the callee retains a borrowed obj
     * return, so the caller's result owns a reference of its own.
     */
    public static bool $rcRecvTemp = true;

    /**
     * `MANTICORE_RC_ELEM_OWNS=1` — do not veto a property slot's element drop
     * because of an element read whose CONSUMER takes a reference.
     *
     * An element read is a real borrow (it emits no retain — `retain_element`
     * counts element STORES), so the veto is load-bearing in general. But the
     * value of a StoreLocal is retained by rcRetainByType and a returned one by
     * isBorrowedObjReturn; those own what they read. Vetoing the DECLARING CLASS
     * for them leaks every element of the slot — `Parser::$tokens` is the case
     * that put 9,236,608 Lexer\\Token allocations against ~0 reclaims.
     *
     * ⚠ OPT-IN. Getting this wrong is a use-after-free on a live element,
     * invisible under Zend and at -O0.
     */
    public static bool $rcElemOwns = false;

    /**
     * ON by default; `MANTICORE_RC_ELEM_TYPE=0` opts out. Let a CONCRETE element
     * type refine a local's recorded release type after the first store.
     *
     * `$a = []` is `vec[unknown]`, and it is usually the ONLY store_local to the
     * name (appends are store_element), so first-write-wins froze the release as
     * a plain buffer drop while inference had long since retyped the loads to
     * `vec[obj<T>]`. Every element leaked. Upgrade is UNKNOWN -> concrete only:
     * it deepens a release that already ran, it never redirects one.
     *
     * ⚠ Load-bearing for {@see $rcPropDrop}, not merely additive: without the
     * refinement {@see Mir\Passes\EmitLlvmMemory::arrayRetainFlavor} answers a
     * plain `vec` for the store, the flavors disagree, and every slot is vetoed.
     * The pair moves the probe 60000/20000 → 60000/60000; either one alone
     * leaves it at 60000/20000.
     */
    public static bool $rcElemType = true;

    /**
     * ON by default; `MANTICORE_RC_PROP_DROP=0` opts out. Let a CLASS DROP body
     * give back the element refs its property slot owns, on every release
     * rather than only at rc → 0.
     *
     * The slot's store retained at element depth ({@see
     * Mir\Passes\EmitLlvm::$propOwnElem} proves it for every store in the
     * module), so the slot holds one ref per element. `__mir_array_release_obj`
     * walks elements only at rc → 0, so an object dying while ANOTHER owner
     * still holds the buffer returns nothing and strands what its own store
     * took: `$tmp = []; $tmp[] = new Tok; $h->els = $tmp;` leaks every element
     * — i.e. `Parser::$tokens`, the 9.2M `Lexer\Token`.
     *
     * ⚠ The drop body is `linkonce_odr` and coalesces by name, so the verdict
     * must be identical in every module that emits the class. An IMPORTED or
     * PRELUDE class is therefore excluded outright, and a library module — which
     * cannot see its callers' stores at all — never proves a slot.
     *
     * The opt-out stays because the failure this guards against is an
     * OVER-release: invisible under Zend, invisible at -O0, and often invisible
     * until gen-2. One env var bisects it in a single run.
     */
    public static bool $rcPropDrop = true;

    /**
     * `MANTICORE_RC_CTOR_ARG=1` — release a fresh obj / vec / assoc TEMP passed
     * to a constructor, the way `emitCall` already does for a free function.
     *
     * Without it `new Parser((new Lexer())->scan($src))` strands the array's own
     * reference and one element ref per token, forever: 168 411 live
     * `Lexer\Token` from ONE compiled file, 64% of the compiler's live objects,
     * never freed. The census that found it is `[CLASS] idx= alloc= free=`.
     *
     * ⚠ OPT-IN, because releasing it correctly EXPOSES a second, older bug it
     * was masking: `array_merge` copies elements with no retain (its arrays are
     * bare `array`, so the element channel is erased and `rcRetainByType`
     * answers '' for a cell), so the merged array holds BORROWS. Freeing the
     * caller's temp then frees a live object under it — `hetero_prop_default_vs_getter`
     * prints an empty `$d->style()->n`. The fix for THAT is an erased element
     * copy retaining through {@see Mir\Passes\EmitLlvm::retainCellPayload}, the
     * same tag-dispatched discipline the bag store already uses; until it lands,
     * this stays off and the leak stays.
     */
    public static bool $rcCtorArgTemp = true;

    /**
     * `MANTICORE_RC_SYM_ELEM=1` — every release of an element-owning array
     * gives one element ref back, not only at rc → 0.
     *
     * The arithmetic: element refs are `base + 1 per retain`, and there is
     * one more release than there are retains. A symmetric release therefore
     * hands back `retains + 1 = base + retains` — exactly what was taken. Mix
     * ONE buffer-only release into that chain and its element ref is never
     * returned: `$this->stmts = $s` retains at element depth while the
     * caller's local release is buffer-only, so overwriting a `?Block $body`
     * freed the Block and nothing under it — 1 object where php frees 149,
     * which is why a build's MIR is 2.5%% reclaimed.
     *
     * ON by default; `MANTICORE_RC_SYM_ELEM=0` is the kill switch. The
     * element-drop epic recorded that making this symmetric turned 5 AOT cases
     * red — measured before the class drop, the ctor arg temp, `array_merge`'s
     * element copy and `get_object_vars` were fixed. Re-counted on top of those:
     * **zero**, suite 1034/1036.
     */
    public static bool $rcSymElem = true;

    /**
     * A local initialised from an ELEMENT READ co-owns what the read hands it.
     *
     * ON by default; `MANTICORE_RC_ELEM_READ_OWNS=0` is the kill switch.
     * Without it the read is a pure borrow, which is a LIVE use-after-free:
     * `$keep = $m['a']; unset($m); return $keep;` returns freed memory
     * (php: `alive`, ours: empty), silently and with DEBUG_VERIFY clean —
     * `tests/aot/cases/elem_read_borrow_uaf.php`. It is also the precondition
     * the element SLOT drop waits on: an overwrite or an unset cannot release
     * the old value while a borrowed local may still point at it.
     *
     * ⚠ BOTH HALVES OR NEITHER: if only the emitter says owned the value
     * LEAKS, if only the pass does the release has no matching retain and the
     * value is DOUBLE-FREED. Neither half decides on its own — both ask
     * {@see Mir\Passes\InsertMemoryOps::elemReadCoOwns}, and the retain is
     * taken at the DESTINATION SLOT's depth, never the value's.
     */
    public static bool $rcElemReadOwns = true;

    /**
     * The element SLOT releases the value an overwrite or an unset takes off it.
     *
     * ON by default; `MANTICORE_RC_ELEM_SLOT_DROP=0` is the kill switch.
     * `$a[$k] = $new` and `unset($a[$k])` retained the new value and dropped
     * NOTHING, so every value an array ever displaced was immortal — the array
     * analogue of the property leak {@see $rcPropDrop} closed.
     *
     * Its PRECONDITION is {@see $rcElemReadOwns}: while an element read was a
     * pure borrow, a local could still point at what the slot was about to
     * free, and this drop would have been a use-after-free. The two ship as one
     * ladder — turning the read back into a borrow REQUIRES turning this off.
     *
     * Gated on a slot whose element type NAMES an rc flavor
     * ({@see EmitLlvm::elemSlotDropFlavor}); an erased or cell element is left
     * alone, because `cell` is a static CLAIM and not a runtime guarantee.
     */
    public static bool $rcElemSlotDrop = true;

    /**
     * Which ELEMENT KINDS {@see $rcElemSlotDrop} may drop — `MANTICORE_ELEM_DROP_KINDS`
     * overrides, and an empty value means ALL.
     *
     * `str` is OUT of the default and that is a KNOWN OPEN BUG, not caution:
     * a compiler built with string-element drops MISCOMPILES — it emits a
     * second, plain-linkage copy of a runtime helper past the end of the
     * module (`invalid redefinition of function '__mir_str_canon_int'`), which
     * reddens 29 AOT cases (reflection, unser_*, destruct). obj and arr are
     * each green on a two-generation self-build; str alone reproduces the
     * whole cluster. Repro:
     *
     *   MANTICORE_ELEM_DROP_KINDS=str bin/build && bin/build
     *   bin/manticore compile tests/aot/cases/cell_local_destruct.php -o /tmp/x
     *
     * The front is clean (`dump-ast` / `dump-mir` are byte-identical), so the
     * corruption is in EmitLlvm's TEXT assembly, downstream of MIR.
     */
    public static string $elemDropKinds = 'obj,arr';

    /**
     * A `foreach` VALUE var CO-OWNS what the loop hands it.
     *
     * ⛔ OFF by default — `MANTICORE_RC_FOREACH_VALUE_OWNS=1` opts in. The
     * DIVERGENCE below is real and reproducible (`probe/fe2.php`, `fe3.php`),
     * but every version of the retain written so far builds a compiler that
     * SIGSEGVs in `Walk::children` from a recursive scan — `InferScans::
     * spreadElemOrigin` with the per-name veto in place, `scanByRefCaptureNode`
     * without it. A cold seed with this OFF and string element drops ON is
     * GREEN, so the two are independent: this is the open half.
     * php gives the loop variable a VALUE (rc++), so `foreach ($m as $k => $v)
     * { $m[$k] = ''; use($v); }` keeps $v intact. We handed out a pure BORROW of
     * the element word, which was harmless only while nothing ever dropped an
     * element off a LIVE array — {@see $rcElemSlotDrop} does, and that pattern
     * is `EmitLlvm::drainLazyHelpers` itself: it blanks the slot it is iterating
     * and then writes the value it just freed. That is the whole 29-case
     * self-host cluster.
     *
     * ⚠ BOTH HALVES OR NEITHER, and they ask one predicate
     * ({@see Mir\Passes\InsertMemoryOps::foreachValueCoOwns}): the emitter takes
     * the +1 and the pass stops BLOCKING the name so scope exit gives it back.
     * Restricted to a PROVEN vec/assoc base, which is exactly the condition
     * under which `emitForeach` takes its unified-array path — a generator, a
     * Traversable or an erased carrier classifies at RUNTIME and the two halves
     * could not agree about it.
     */
    public static bool $rcForeachValueOwns = false;

    /** BISECT ONLY: when non-empty, foreach co-ownership applies to a function
     *  whose name CONTAINS this substring, and to no other. `MANTICORE_FE_ONLY`.
     *  Lets the self-host crash be narrowed to one emitting function in ~log2(N)
     *  builds instead of by hypothesis. */
    public static string $feOnly = '';


    /**
     * `MANTICORE_ARR_RC_TRACE=1` — print every array retain / release with the
     * buffer address, its length and the RESULTING rc.
     *
     * Whole-program counters say how many refs are unbalanced; they cannot say
     * WHICH buffer never reaches zero. Reading emitter predicates instead cost
     * two no-op fixes and one masked one, so this observes the thing itself.
     * Volume is fine on a small input: one 3-line file lexes into a few hundred
     * array ops.
     */
    public static bool $arrRcTrace = false;

    /**
     * Drain the cycle-collector root buffer at a threshold, the way php does.
     * ON by default; `MANTICORE_AUTO_GC=0` (or `off`) disables it, any other
     * value sets the threshold.
     *
     * A default is a claim about what a php PROGRAM should do, and php collects
     * cycles. What kept this off was one defect, not the design: with the
     * collector running objects finally DIED, and a `get_object_vars()` that had
     * always given back a reference it never took became a double free instead
     * of a leak. With that closed the whole corpus passes either way (1032/1034,
     * 0 failed, same builder), and the collector is worth **-42.5%% RSS /
     * -49.8%% peak footprint on the FRONT** (`dump-mir`, no clang) for ~6%% more
     * CPU on a full build; objects reclaimed go from 0.20%% to 33.06%%.
     *
     * ⚠ Reclaim still stops well short of everything: a ref held through an
     * ARRAY element is never trial-deleted, so a cycle closed through an array
     * is not collected.
     *
     * The buffer used to have NO drain at all. A decrement leaving rc > 0
     * buffers the object as
     * a possible cycle root, and `__mir_rc_release` then refuses to free it at
     * rc 0 because the collector owns it — but the collector only ever ran from
     * an explicit `gc_collect_cycles()`, which the compiler never calls. So
     * every object retained more than once leaked BY CONSTRUCTION: 0.27%% of
     * objects freed against 96%% of arrays, and 0 of 9.2M `Lexer\Token`.
     */
    public static bool $autoGc = true;

    /**
     * `MANTICORE_CC_TRACE=1` — print every object the collector frees and every
     * object ordinary rc frees, plus the root buffer's address and count around
     * each collection.
     *
     * The collector's own double free is a claim about WHO freed a pointer
     * first; only a per-pointer log answers it. The buffer address answers the
     * second question in the same run: whether a push during a collection
     * reallocs the array the collection is walking.
     */
    public static bool $ccTrace = false;

    /** Roots buffered before an automatic collection. php uses 10 000. */
    public static int $autoGcThreshold = 10000;

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
        $env = \getenv('MANTICORE_ROOT_TRACE');
        if ($env !== false && $env !== '0' && $env !== '') {
            self::$rootTrace = true;
            Stats::rootInit();
        }
        $env = \getenv('MANTICORE_COMPACT_CACHES');
        if ($env !== false && $env !== '0' && $env !== '') {
            self::$compactCaches = true;
        }
        $env = \getenv('MANTICORE_RC_RETURN_OWNS');
        if ($env === '0' || $env === 'off') { self::$rcReturnOwns = false; }
        $env = \getenv('MANTICORE_RC_RECV_TEMP');
        if ($env === '0' || $env === 'off') { self::$rcRecvTemp = false; }
        $env = \getenv('MANTICORE_RC_ELEM_OWNS');
        if ($env !== false && $env !== '0' && $env !== '') { self::$rcElemOwns = true; }
        $env = \getenv('MANTICORE_RC_ELEM_TYPE');
        if ($env === '0' || $env === 'off') { self::$rcElemType = false; }
        $env = \getenv('MANTICORE_RC_PROP_DROP');
        if ($env === '0' || $env === 'off') { self::$rcPropDrop = false; }
        $env = \getenv('MANTICORE_RC_CTOR_ARG');
        if ($env === '0' || $env === 'off') { self::$rcCtorArgTemp = false; }
        $env = \getenv('MANTICORE_RC_ELEM_READ_OWNS');
        if ($env === '0' || $env === 'off') { self::$rcElemReadOwns = false; }
        $env = \getenv('MANTICORE_RC_ELEM_SLOT_DROP');
        if ($env === '0' || $env === 'off') { self::$rcElemSlotDrop = false; }
        $env = \getenv('MANTICORE_RC_FOREACH_VALUE_OWNS');
        if ($env !== false && $env !== '0' && $env !== '' && $env !== 'off') { self::$rcForeachValueOwns = true; }
        $env = \getenv('MANTICORE_FE_ONLY');
        if ($env !== false && $env !== '') { self::$feOnly = $env; }
        $env = \getenv('MANTICORE_ELEM_DROP_KINDS');
        if ($env !== false && $env !== '') { self::$elemDropKinds = $env; }
        $env = \getenv('MANTICORE_RC_SYM_ELEM');
        if ($env === '0' || $env === 'off') { self::$rcSymElem = false; }
        $env = \getenv('MANTICORE_ARR_RC_TRACE');
        if ($env !== false && $env !== '0' && $env !== '') { self::$arrRcTrace = true; }
        $env = \getenv('MANTICORE_CC_TRACE');
        if ($env !== false && $env !== '0' && $env !== '') { self::$ccTrace = true; }
        $env = \getenv('MANTICORE_AUTO_GC');
        if ($env === '0' || $env === 'off') {
            self::$autoGc = false;
        } elseif ($env !== false && $env !== '') {
            self::$autoGc = true;
            if (\ctype_digit($env)) { self::$autoGcThreshold = (int)$env; }
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
        // The phase timeline alone. Read AFTER the full flag so that setting
        // both leaves the full report on ({@see Stats::$phaseTrace} says why a
        // large target can only use this one).
        $env = \getenv('MANTICORE_PHASE_TRACE');
        if ($env !== false && $env !== '0' && $env !== '') {
            Stats::$phaseTrace = true;
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
