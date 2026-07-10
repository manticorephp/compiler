<?php

namespace Compile\Mir\Passes;

use Compile\Mir\Add;
use Compile\Mir\Block;
use Compile\Mir\ArrayAccess_;
use Compile\Mir\ArrayLit;
use Compile\Mir\Spread_;
use Compile\Mir\BoolConst;
use Compile\Mir\MethodCall_;
use Compile\Mir\NewObj;
use Compile\Mir\Clone_;
use Compile\Mir\PropertyAccess_;
use Compile\Mir\StoreProperty;
use Compile\Mir\DynProp_;
use Compile\Mir\StoreDynProp_;
use Compile\Mir\StaticCall_;
use Compile\Mir\Break_;
use Compile\Mir\Call;
use Compile\Mir\Closure_;
use Compile\Mir\Invoke_;
use Compile\Mir\NullCoalesce_;
use Compile\Mir\Instanceof_;
use Compile\Mir\Cast;
use Compile\Mir\Cmp;
use Compile\Mir\Concat;
use Compile\Mir\Continue_;
use Compile\Mir\Div;
use Compile\Mir\Echo_;
use Compile\Mir\FloatConst;
use Compile\Mir\FunctionDef;
use Compile\Mir\IncDec;
use Compile\Mir\StaticProp_;
use Compile\Mir\StoreStaticProp_;
use Compile\Mir\StaticLocalDecl_;
use Compile\Mir\Isset_;
use Compile\Mir\Unset_;
use Compile\Mir\ClassName_;
use Compile\Mir\RefAlias_;
use Compile\Mir\RefBind_;
use Compile\Mir\RefAddr_;
use Compile\Mir\Throw_;
use Compile\Mir\TryCatch_;
use Compile\Mir\MirCatch;
use Compile\Mir\Ternary;
use Compile\Mir\Switch_;
use Compile\Mir\SwitchArm_;
use Compile\Mir\Match_;
use Compile\Mir\MatchArm_;
use Compile\Mir\If_;
use Compile\Mir\IntConst;
use Compile\Mir\LoadLocal;
use Compile\Mir\Mod;
use Compile\Mir\Module;
use Compile\Mir\Mul;
use Compile\Mir\Neg;
use Compile\Mir\Node;
use Compile\Mir\Not_;
use Compile\Mir\NullConst;
use Compile\Mir\Pass;
use Compile\Mir\Return_;
use Compile\Mir\StoreElement;
use Compile\Mir\StoreLocal;
use Compile\Mir\StringConst;
use Compile\Mir\Sub;
use Compile\Mir\Type;
use Compile\Mir\Foreach_;
use Compile\Mir\For_;
use Compile\Mir\DoWhile_;
use Compile\Mir\While_;
use Compile\Runtime\BareHost;
use Compile\Runtime\UnifiedArrayRuntime;
use Codegen\Llvm\Module as LlvmModule;

/**
 * MIR → LLVM IR text emitter.
 *
 * Self-contained — does not go through `Codegen\\Llvm\\*`. Builds
 * the module text as a string accumulator. Output matches the
 * existing backend's calling convention so binaries link against
 * the same libc primitives (`printf`).
 *
 * Phase G scope: scalar primitives (int, bool, string), arithmetic
 * (+ - *, neg, not), comparison, locals (alloca-based), direct
 * intra-module calls, `if`/`while`/`break`/`continue`, `echo`,
 * `return`. Skipped this round (planned Phase H+): float (NaN box),
 * arrays / objects (RC ABI), property access, dynamic calls.
 *
 * Locals are i64 allocas at the function entry; SSA values are
 * `%r0`, `%r1`, … allocated in walk order. Strings are interned
 * into a per-module pool and emitted as `@.str.N`.
 *
 * Each MIR function `fn $name($p1, ...) -> T` lowers to an LLVM
 * `define i64 @manticore_$name(i64 %p1, ...) { entry: ... }`.
 * The `__main` MIR function lowers to `define i32 @main(i32, ptr)`
 * so the linker has the libc entry point.
 */
final class EmitLlvm
{
    use EmitLlvmRuntime;
    use EmitLlvmBuiltins;
    use EmitLlvmExceptions;
    use EmitLlvmObjects;

    public function name(): string { return 'emit-llvm'; }

    /** @var array<string, int> string → pool id */
    private array $stringPool = [];

    private int $nextId = 0;
    private int $nextLabel = 0;
    /** @var array<string,string> user goto-label name → stable LLVM block label
     *  (allocated on first goto/label reference; reset per function). */
    private array $userLabels = [];
    private int $switchCounter = 0;

    /** @var array<string, string> local name → alloca SSA id */
    private array $slots = [];

    /** @var array<string, string> static-local name → global cell (this fn) */
    private array $globalBackedLocals = [];


    private string $breakLabel = '';
    private string $continueLabel = '';
    // Out-slot for {@see cellTagIr}: the SSA reg holding the computed cell tag.
    private string $cellTagReg = '';
    /** @var string[] enclosing loops' break targets (innermost last) */
    private array $breakStack = [];
    /** @var string[] enclosing loops' continue targets (innermost last) */
    private array $continueStack = [];

    /** @var array<string, \Compile\Mir\ClassDef> */
    private array $classes = [];

    /** Method FunctionDef name → backtrace frame display ("Class->method" /
     *  "Class::method"), from {@see \Compile\Mir\Module::$methodDisplay}. Used
     *  at a method's entry to stamp the correct frame name (the call-site
     *  receiver-class read drifts under the self-host).
     *  @var array<string, string> — @var pins the string value type (a bare
     *  `array` erases it: values read back as raw pointer ints). */
    private array $methodDisplay = [];


    /** @var array<string, \Compile\Mir\EnumDef> */
    private array $enums = [];

    /** @var array<string, true> interface names (interface_exists fold) */
    private array $interfaceNames = [];

    /** @var array<string, true> trait names (trait_exists fold) */
    private array $traitNames = [];

    /** @var array<string, bool[]> fn name → per-param by-ref mask */
    private array $fnRefParams = [];
    /** @var array<string, bool[]> fn → which params are tagged (cell) */
    private array $fnTaggedParams = [];
    /** @var array<string, Type[]> fn name → per-param declared type */
    private array $fnParamTypes = [];
    /** @var array<string, array<int, ?Node>> fn name → per-param default node */
    private array $fnParamDefaults = [];
    /** Arg-list suffix produced by the most recent {@see emitDefaultArgPad}. */
    private string $lastPadArgs = '';

    /** @var array<string, true> by-ref param names in the current fn */
    private array $refLocals = [];

    /** @var array<Node[]> enclosing finally bodies (outer-first) active at the
     *  current emit point — a `return` inside a try runs them before exiting. */
    private array $finallyStack = [];

    /** @var array<string, true> locals captured by-ref by a closure (heap-boxed) */
    private array $byRefCaptured = [];

    // ── generator state (set while emitting a `$resume` function) ──
    /** True while emitting a generator resume body. */
    private bool $inGenerator = false;
    /** Running yield index (1..N) — each yield's suspend/resume state number. */
    private int $genYieldCounter = 0;
    /** SSA ptr to the frame's `state` word (entry GEP, dominates all). */
    private string $genStatePtr = '';
    /** SSA ptr to the frame's `current` word (entry GEP). */
    private string $genCurrentPtr = '';
    /** SSA ptr to the frame's `key` word (yielded key). */
    private string $genKeyPtr = '';
    /** SSA ptr to the frame's `nextkey` word (auto-increment key counter). */
    private string $genNextKeyPtr = '';
    /** SSA ptr to the frame's `sent` word (value passed in via send()). */
    private string $genSentPtr = '';
    /** Name of the function currently being emitted (property-hook self-ref guard). */
    private string $currentFnName = '';

    /** Emit the runtime call-stack + instrument user calls (backtrace support). */
    private bool $needsBacktrace = false;

    /** Program source path (exception file() / trace frames). */
    private string $sourceFile = '';

    /** Display name of the function currently being emitted, for a trace frame. */
    private string $currentFnDisplay = '';

    /** SSA ptr to the frame's `retval` word (return value for getReturn()). */
    private string $genRetvalPtr = '';

    /** @var array<string, bool> fn name → returns by reference */
    private array $fnReturnsByRef = [];

    /** True while the current fn returns by-ref (emitReturn yields an address). */
    private bool $currentReturnsByRef = false;

    /** True while emitting a `$r = &fn()` bind (suppress call-result deref). */
    private bool $rawRefCall = false;

    /** Return type of the function currently being emitted (cell → box). */
    private ?Type $currentReturnType = null;

    /** Whether the function currently being emitted is a closure (uniform ABI:
     *  scalar params/returns travel as tagged cells). */
    private bool $currentFnIsClosure = false;

    /** Scratch regs threaded out of {@see emitBagPtr}. */
    private string $bagSlotReg = '';
    private string $bagPtrReg = '';

    /** @var array<string, int> closure fn name → capture count */
    private array $closureCaptures = [];

    /** Whether any `.` concat was emitted (gates the string runtime). */
    private bool $needsConcat = false;
    /** Whether amortized self-append (`$s .= …`) was emitted (gates __mir_str_append). */
    private bool $needsStrAppend = false;
    /** Whether any arena alloc / scope was emitted (gates the arena runtime). */
    private bool $needsArena = false;
    /** Whether an rc retain/release was emitted (gates the rc runtime). */
    private bool $needsRc = false;
    /** Out-param for {@see emitLoadClassId} — the class_id SSA reg (avoids a
     *  list-destructure return, which self-host doesn't support). */
    private string $classIdReg = '';
    /** True if `gc_collect_cycles()` is used — gates the Bacon-Rajan cycle
     *  collector runtime AND its `cc_add_root` hook in obj release. Opt-in:
     *  programs that never call it pay zero CC overhead (cycles just leak). */
    private bool $needsCc = false;
    /** True if any string is rc retained/released — emit the sentinel-
     *  guarded `@__mir_rc_retain_str` / `@__mir_rc_release_str`. */
    private bool $needsStrRc = false;
    /** True if a loop resets the arena per iteration — emit the position
     *  save/restore helpers (`@__mir_arena_used` / `@__mir_arena_restore`). */
    private bool $needsArenaReset = false;
    /** @var array<string, bool> vec locals mutated in the current fn
     *  (append / element store) — drive copy-on-assign value semantics. */
    private array $mutatedVecLocals = [];
    /** True while emitting a function that opened an arena scope — its
     *  every `ret` must run `@__mir_arena_leave` first. */
    private bool $currentFnHasArena = false;
    /** Body of the function currently being emitted — used by the loop
     *  arena-reset liveness check to see uses OUTSIDE the loop. */
    private ?Node $currentFnBody = null;
    /** @var array<string, \Compile\Mir\MemoryOp_> owned RcHeap obj/vec/str
     *  locals of the current fn → their rc_release MemoryOp node (flavor is
     *  re-derived per use via rcReleaseFlavor; storing the flavor string
     *  here corrupts under the self-host backend). Released before every
     *  `ret` except the returned one (transfer); slots null-inited. */
    private array $currentRcObjLocals = [];
    /** @var array<string, bool> owned rcObj locals of the current fn whose
     *  value flows into a BORROWING container store (a vec/assoc/property/
     *  array-lit store that does NOT retain it — erased element type, no
     *  usable fallback). Ownership transfers to the container, so the
     *  local's scope-exit / pre-return / reassign release is SUPPRESSED.
     *  This is B2 escape-driven ownership: it kills the over-release UAF
     *  (the enum/arena heisenbug) by moving instead of adding a retain
     *  (adding retains pushed the binary toward the corruption boundary).
     *  Worst case is a leak (the safe direction), never a double-free. */
    private array $transferredLocals = [];
    /** @var array<string, bool> owned vec/assoc locals of the current fn whose
     *  BUFFER is shared with an outliving owner: passed as a (by-value) call
     *  argument, so the callee co-owns the buffer AND its retained element
     *  refs (the +1 each `array_append` adds). Their scope-exit release must
     *  drop the BUFFER ONLY (plain `array_release`), never element-drop:
     *  `array_release_obj/_str` walks and -1's every element, which on a
     *  co-owned buffer double-frees the shared elements. This is the parser
     *  `$args = parseArgList(); return Expr::call(..., $args, ...)` UAF — the
     *  Expr node retains the buffer, then the local's `release_obj` kills the
     *  elements the node still references. Element-drop stays valid only for a
     *  SOLE-owner confined vec (built and discarded, never shared). */
    private array $elementSharedLocals = [];
    /** @var array<string, bool> param names of the current fn (transfer
     *  skips params — they are retained-on-entry by initRcObjSlots, so
     *  suppressing their release would unbalance that entry retain). */
    private array $currentParamNames = [];
    /** Set by emitArrayLit when it bump-allocated a vec, read by the
     *  enclosing emitStoreLocal to mark the target as an arena vec. */
    private bool $vecAllocArena = false;
    /** @var array<string, bool> locals holding an arena-allocated vec —
     *  their `$x[]=` appends must use @__mir_arena_realloc. */
    private array $arenaVecLocals = [];
    private bool $needsFloatStr = false;
    private bool $needsFloatShortest = false;
    private bool $needsStrtol = false;
    private bool $needsStrtod = false;
    private bool $needsStrcmp = false;
    private bool $needsIntStr = false;
    private bool $needsExceptions = false;
    /** This module defines `@main` (the user program, not the stdlib library). */
    private bool $moduleHasMain = false;
    /** Module uses `$gen->throw($e)` → emit the per-yield injection check. */
    private bool $genThrowUsed = false;
    private bool $needsTagged = false;
    private bool $needsTaggedEcho = false;
    private bool $needsTaggedToStr = false;
    private bool $needsImplodeCell = false;
    private bool $needsTaggedToInt = false;
    private bool $needsTaggedToFloat = false;
    private bool $needsTaggedCompare = false;
    private bool $needsTaggedEq = false;
    private bool $needsTaggedArith = false;
    private bool $needsTaggedTruthy = false;
    /** Module indexes an array with a `mixed`/cell key → emit the
     *  runtime int-vs-string key dispatch helpers (`__mir_array_*_cell`). */
    private bool $needsCellKey = false;
    /** @var array<string, string> libc symbol → declare line (builtins) */
    private array $libcExtra = [];
    /**
     * Native FFI-boundary primitives (`manticore_rt_*`) called but not
     * PHP-defined. Declared as externs so the module assembles; the
     * no-Rust bootstrap link-stubs them (the compiler never invokes the
     * FFI path at compile time). symbol → declare line.
     * @var array<string, string>
     */
    private array $rtExterns = [];
    /** @var array<string, bool> mangled module-fn name → defined (for extern detection) */
    private array $definedFns = [];
    /**
     * Library build (prebuilt stdlib.o): suppress the `@main` entry point so
     * the object links cleanly alongside a user program's own `@main`. Set by
     * the `--emit-library` compile path.
     */
    public bool $emitLibrary = false;
    private bool $needsSubstr = false;
    private bool $needsStrRepeat = false;
    private bool $needsStrtolower = false;
    private bool $needsStrtoupper = false;
    private bool $needsIpow = false;
    private bool $needsAddslashes = false;
    private bool $needsJsonEscape = false;
    private bool $needsJsonEnc = false;
    private bool $needsStrReplaceOne = false;
    /** Main captured argc/argv → emit @manticore_cli_argc/argv definitions. */
    private bool $needsCliArgv = false;
    /** STDIN/STDOUT/STDERR used → emit @manticore_std{in,out,err} accessors. */
    private bool $needsStdStreams = false;
    private bool $needsStrpos = false;
    private bool $needsStrExplode = false;
    /** Scratch: address reg set by foreachElemAddr / foreachKeyAddr. */
    private string $feAddr = '';
    /** Scratch: result reg set by emitVirtualDispatch. */
    private string $vdResult = '';
    /** @var string[] module global cell names (static props/locals/global) */
    private array $globalNames = [];
    /** @var Node[] parallel default-init nodes for $globalNames */
    private array $globalDefaults = [];
    /** @var string[] names declared `global $x` — __main shares the cell */
    private array $globalVarNames = [];

    public function emit(Module $module): string
    {
        // Arena arrays force the arena runtime on: the unified-array grow /
        // promote / index paths reference @__mir_arena_* under this flag, so
        // those symbols must be emitted even if no string took the arena path.
        if (\Compile\Debug::$arenaArrays) { $this->needsArena = true; }
        $this->stringPool = [];
        $this->classes = $module->classes;
        $this->enums = $module->enums;
        $this->methodDisplay = $module->needsBacktrace ? $module->methodDisplay : [];
        $this->interfaceNames = $module->interfaceNames;
        $this->traitNames = $module->traitNames;
        $this->closureCaptures = $module->closureCaptures;
        $this->globalNames = $module->globalNames;
        $this->globalDefaults = $module->globalDefaults;
        $this->globalVarNames = $module->globalVarNames;
        $this->needsBacktrace = $module->needsBacktrace;
        $this->sourceFile = $module->sourceFile;
        // Per-function by-ref + tagged(cell) param masks for call sites.
        $this->fnRefParams = [];
        $this->fnTaggedParams = [];
        $this->fnParamTypes = [];
        $this->fnReturnsByRef = [];
        foreach ($module->functions as $fn) {
            $mask = [];
            $tmask = [];
            $ptypes = [];
            $pdefs = [];
            foreach ($fn->params as $p) {
                $mask[] = $p->byRef;
                $tmask[] = ($p->type->kind === Type::KIND_CELL);
                $ptypes[] = $p->type;
                $pdefs[] = $p->default;
            }
            $this->fnRefParams[$fn->name] = $mask;
            $this->fnTaggedParams[$fn->name] = $tmask;
            $this->fnParamTypes[$fn->name] = $ptypes;
            $this->fnParamDefaults[$fn->name] = $pdefs;
            $this->fnReturnsByRef[$fn->name] = $fn->returnsByRef;
            $this->definedFns[$this->mangle($fn->name)] = true;
            if ($fn->name === '__main') { $this->moduleHasMain = true; }
        }
        // Pre-scan for `$gen->throw($e)`: a yield resume point must check for
        // an injected exception. Must be known BEFORE emitting any generator
        // body (emitYield emits the check inline). Over-triggering on a user
        // `->throw()` method only adds a dead load+branch + one global.
        $this->genThrowUsed = false;
        foreach ($module->functions as $fn) {
            if ($this->scanGenThrow($fn->body)) { $this->genThrowUsed = true; break; }
        }
        if ($this->genThrowUsed) { $this->needsExceptions = true; }
        // Pre-scan for throw / try-catch so `needsExceptions` is settled BEFORE
        // any function body emits — @main's base landing pad (emitMain) is gated
        // on it and @main may be emitted before a throwing function is reached.
        if (!$this->needsExceptions) {
            foreach ($module->functions as $fn) {
                if ($this->scanUsesExceptions($fn->body)) { $this->needsExceptions = true; break; }
            }
        }
        // Classify cell/`mixed` properties: a name that is EVER stored a
        // non-scalar (array / string / object / unknown / general cell) value
        // stays RAW (the SPL cell-array backing `$__s` etc. — rc-managed +
        // boxToCell would rebuild it). A cell prop whose every store is a non-rc
        // scalar (int/float/bool/null/numericCell) is self-describing: it
        // defaults to a boxed NULL and box-stores, so a read / var_dump / `===
        // null` dispatch by tag instead of mis-reading a raw 0. Name-global
        // (sidesteps inheritance / class-qualification — over-conservative is
        // safe: a name shared with a non-scalar prop just stays raw).
        $this->cellPropNotBoxable = [];
        $this->cellPropArrayBase = [];
        $this->cellPropHasArrayStore = [];
        $this->cellPropHasInPlaceBox = [];
        foreach ($module->functions as $fn) { $this->scanCellPropStores($fn->body); }
        $functionBodies = '';
        foreach ($module->functions as $fn) {
            $functionBodies .= $this->emitFunction($fn);
        }
        // Mark every RUNTIME helper (`@__mir_*`, `@__manticore_*`, cc/box
        // helpers) `linkonce_odr` so the linker dedups them when a user `.o`
        // is linked against the prebuilt `stdlib.o` — both objects carry the
        // same preamble. Only the preamble block is rewritten; user / stdlib
        // PHP functions stay external (unique) and `@main` lives in the
        // bodies, never the preamble. linkonce_odr is a no-op for a lone `.o`.
        $preamble = $this->linkonceRuntime($this->emitPreamble());
        return $preamble . $functionBodies;
    }

    /**
     * Promote every `define` in the runtime preamble to `linkonce_odr`
     * linkage. The preamble's defines are all shared runtime helpers; tagging
     * them linkonce_odr lets two objects (user + stdlib) coexist at link time.
     * Read-only constants (string pool, `@__mir_zero_word`) stay `internal` —
     * file-local, foldable, identical per-.o. But MUTABLE runtime STATE
     * (arena/jmp/argv/cc/prof globals) is emitted `linkonce_odr` at its def
     * site so it coalesces to ONE address across the two objects — see those
     * defs. Only `define` lines (helpers) need touching here.
     */
    private function linkonceRuntime(string $preamble): string
    {
        // explode/implode, NOT str_replace: the bundled str_replace appends a
        // byte at a time, which is O(n²) and leaks every intermediate in the
        // self-host runtime (obj_releases=0). The preamble carries the whole
        // string pool, so on a large program that blew memory to multi-GB.
        // Splitting on the ~50 `\ndefine ` occurrences is linear.
        return \implode("\ndefine linkonce_odr ", \explode("\ndefine ", $preamble));
    }

    private function emitPreamble(): string
    {
        // Settle the rc-runtime flags for every droppable PROPERTY type
        // before any helper is emitted, so drop_dispatch can release vec /
        // assoc props (not just obj / string). Without this a class with an
        // `array`/assoc prop leaks the buffer + its elements on free.
        $this->scanDropFlags();
        // The unified PhpArray release helpers always drop hashed string keys
        // (→ __mir_rc_release_str) and the _obj variant drops obj values
        // (→ __mir_rc_release); force both — the unified runtime always emits.
        $this->needsRc = true;
        $this->needsStrRc = true;
        $out  = "; ModuleID = 'mir'\n";
        $out .= "source_filename = \"mir\"\n\n";
        $out .= "@.fmt.d = private unnamed_addr constant [5 x i8] c\"%lld\\00\", align 1\n";
        $out .= "@.fmt.g = private unnamed_addr constant [3 x i8] c\"%g\\00\", align 1\n";
        $out .= "@.fmt.s = private unnamed_addr constant [3 x i8] c\"%s\\00\", align 1\n";
        // A valid empty C-string — a null `?string` (ptr 0) maps to this for
        // echo / concat so they print "" instead of dereferencing 0.
        // Headered empty sentinel (cap0/len0/rc-1, immortal): data ptr is +24.
        // Reachable by __mir_strlen (emitPtrArg maps a null string here), so it
        // must carry a valid len. Use {@see strSymBytes} for the data pointer.
        $out .= "@.cstr.empty = private unnamed_addr constant { i64, i64, i64, i64, [1 x i8] } { i64 "
            . (string)$this->fnvHash64('') . ", i64 0, i64 0, i64 -1, [1 x i8] c\"\\00\" }, align 8\n";
        $out .= "@.fmt.pg = private unnamed_addr constant [6 x i8] c\"%.14g\\00\", align 1\n";
        $out .= "@.fmt.x = private unnamed_addr constant [5 x i8] c\"%llx\\00\", align 1\n";
        // var_dump of a typed float: shortest round-trip (`%.*g` probed) wrapped
        // in `float(...)`.
        $out .= "@.fmt.vdfloat = private unnamed_addr constant [11 x i8] c\"float(%s)\\0A\\00\", align 1\n";
        $out .= "@.fmt.starg = private unnamed_addr constant [5 x i8] c\"%.*g\\00\", align 1\n";
        $out .= "@.fmt.f0 = private unnamed_addr constant [5 x i8] c\"%.0f\\00\", align 1\n";
        $out .= "@.fmt.p15 = private unnamed_addr constant [6 x i8] c\"%.15g\\00\", align 1\n";
        // Bool echo: `printf("%.*s", b?1:0, "1")` → "1" / "" (PHP echo bool).
        $out .= "@.fmt.ds = private unnamed_addr constant [5 x i8] c\"%.*s\\00\", align 1\n";
        $out .= "@.str.one = private unnamed_addr constant [2 x i8] c\"1\\00\", align 1\n";
        // Module global cells (static props / static locals / `global`).
        // Compute initialisers FIRST so any string defaults intern into
        // the pool before it's rendered below.
        $globalCells = '';
        $gi = 0;
        foreach ($this->globalNames as $gname) {
            $def = $this->globalDefaults[$gi];
            $gi = $gi + 1;
            $globalCells .= $gname . ' = global i64 ' . $this->globalInit($def) . "\n";
        }
        // Emit each interned string constant as a headered @.str.N
        // ({i64 -1, [L x i8]}); the rc word lets a heap string and a
        // literal share one layout so retain/release work on either.
        foreach ($this->stringPool as $value => $id) {
            $out .= $this->strGlobalDef('@.str.' . (string)$id, (string)$value);
        }
        $out .= $globalCells;
        // Zero word: a null vec/assoc base (the empty-literal optimization
        // stores `[]` as a null ptr) is redirected here so a foreach length
        // load reads 0 instead of faulting.
        $out .= "@__mir_zero_word = internal global i64 0\n";
        if ($this->needsBacktrace) {
            // Runtime call-stack for backtraces: parallel name/line rings + depth.
            // linkonce_odr so user.o + stdlib.o share one stack.
            $out .= "@__mir_bt_name = linkonce_odr global [4096 x i64] zeroinitializer\n";
            $out .= "@__mir_bt_line = linkonce_odr global [4096 x i64] zeroinitializer\n";
            $out .= "@__mir_bt_depth = linkonce_odr global i64 0\n";
            $out .= "define void @__mir_bt_push(ptr %name, i64 %line) {\n";
            $out .= "entry:\n";
            $out .= "  %d = load i64, ptr @__mir_bt_depth\n";
            $out .= "  %ok = icmp slt i64 %d, 4096\n";
            $out .= "  br i1 %ok, label %st, label %inc\n";
            $out .= "st:\n";
            $out .= "  %ni = ptrtoint ptr %name to i64\n";
            $out .= "  %np = getelementptr inbounds [4096 x i64], ptr @__mir_bt_name, i64 0, i64 %d\n";
            $out .= "  store i64 %ni, ptr %np\n";
            $out .= "  %lp = getelementptr inbounds [4096 x i64], ptr @__mir_bt_line, i64 0, i64 %d\n";
            $out .= "  store i64 %line, ptr %lp\n";
            $out .= "  br label %inc\n";
            $out .= "inc:\n";
            $out .= "  %d1 = add i64 %d, 1\n";
            $out .= "  store i64 %d1, ptr @__mir_bt_depth\n";
            $out .= "  ret void\n}\n";
            $out .= "define void @__mir_bt_pop() {\n";
            $out .= "entry:\n";
            $out .= "  %d = load i64, ptr @__mir_bt_depth\n";
            $out .= "  %d1 = sub i64 %d, 1\n";
            $out .= "  store i64 %d1, ptr @__mir_bt_depth\n";
            $out .= "  ret void\n}\n";
        }
        if ($this->needsExceptions) {
            // setjmp/longjmp exception runtime. 16 nested-try slots ×
            // 256B jmp_buf (macOS arm64 needs 192). @thrown holds the
            // in-flight exception ptr.
            // linkonce_odr (NOT internal): exception state is touched by
            // linkonce_odr runtime helpers; at -O2 those inline into both
            // user.o + stdlib.o. Per-.o internal copies would split the
            // jmp/thrown state. Coalesce to one address (no-op for a lone .o).
            $out .= "@__mir_jmp_stack = linkonce_odr global [4096 x i8] zeroinitializer\n";
            $out .= "@__mir_jmp_depth = linkonce_odr global i64 0\n";
            $out .= "@__mir_thrown = linkonce_odr global ptr null\n";
            // `$gen->throw($e)` pending injection: non-null = throw at the
            // next yield resume point (the suspended `yield` expression raises).
            if ($this->genThrowUsed) {
                $out .= "@__mir_gen_throw = linkonce_odr global ptr null\n";
            }
            // Top-level fatal for an UNCAUGHT throw. @main installs a base
            // setjmp at slot 0 (depth starts at 1 → user tries take slots 1+);
            // a throw that unwinds past every try longjmps here. Without it a
            // depth-0 throw computes slot -1 → OOB jmp_buf → UB longjmp. Emitted
            // ONLY in the module that owns @main (the user program): its class
            // switch is module-specific, so a linkonce_odr copy in stdlib.o
            // (different class table) would be an ODR mismatch.
            if ($this->moduleHasMain) {
                $out .= "@.fmt.uncaught = private unnamed_addr constant [35 x i8] "
                      . "c\"PHP Fatal error:  Uncaught %s: %s\\0A\\00\", align 1\n";
                $out .= $this->emitUncaughtHandler();
            }
        }
        // Dedup libc declares by symbol — flag-driven needs + the
        // per-builtin needs registered in $libcExtra. A symbol declared
        // twice is a hard LLVM error, so route everything through one map.
        $decls = [];
        $decls['printf'] = "declare i32 @printf(ptr, ...) nofree nounwind";
        $decls['malloc'] = "declare ptr @malloc(i64)";
        $decls['free']   = "declare void @free(ptr)";
        // __mir_realloc_tagged is always emitted (tagged vec grow), so the
        // realloc decl must always be present.
        $decls['realloc'] = "declare ptr @realloc(ptr, i64)";
        // A2 verify mode (MANTICORE_DEBUG_VERIFY): rc helpers abort on an
        // over-release (rc<1 before decrement = double-free / UAF). Gated so
        // production IR is byte-identical.
        if (\Compile\Debug::$verify) {
            $decls['abort'] = "declare void @abort() noreturn";
            $decls['dprintf'] = "declare i32 @dprintf(i32, ptr, ...)";
        }
        if ($this->needsArena) {
            $decls['realloc'] = "declare ptr @realloc(ptr, i64)";
            $decls['memcpy']  = "declare ptr @memcpy(ptr, ptr, i64)";
        }
        if ($this->needsConcat) { $decls['strlen'] = "declare i64 @strlen(ptr)"; }
        if ($this->needsConcat || $this->needsFloatStr || $this->needsIntStr || $this->needsTaggedToStr) { $decls['snprintf'] = "declare i32 @snprintf(ptr, i64, ptr, ...)"; }
        if ($this->needsStrtol) { $decls['strtol'] = "declare i64 @strtol(ptr, ptr, i32)"; }
        if ($this->needsStrcmp) { $decls['strcmp'] = "declare i32 @strcmp(ptr, ptr)"; }
        if ($this->needsExceptions) {
            $decls['setjmp'] = "declare i32 @setjmp(ptr) returns_twice";
            $decls['longjmp'] = "declare void @longjmp(ptr, i32) noreturn";
        }
        // tagged_to_double's string-tag branch parses via strtod, so declare it
        // whenever that helper is emitted (not only on a direct (float)"str" cast).
        if ($this->needsStrtod || $this->needsTaggedToFloat) { $decls['strtod'] = "declare double @strtod(ptr, ptr)"; }
        // Unified PhpArray runtime libc deps (docs/16).
        $decls['realloc'] = "declare ptr @realloc(ptr, i64)";
        $decls['memset']  = "declare ptr @memset(ptr, i32, i64)";
        $decls['memcpy']  = "declare ptr @memcpy(ptr, ptr, i64)";
        $decls['memmove'] = "declare ptr @memmove(ptr, ptr, i64)";
        $decls['memcmp']  = "declare i32 @memcmp(ptr, ptr, i64)";
        $decls['malloc']  = "declare ptr @malloc(i64)";
        $decls['strlen']  = "declare i64 @strlen(ptr)";
        $decls['free']    = "declare void @free(ptr)";
        $decls['strcmp']  = "declare i32 @strcmp(ptr, ptr)";
        foreach ($this->libcExtra as $sym => $line) { $decls[$sym] = $line; }
        foreach ($this->rtExterns as $sym => $line) { $decls[$sym] = $line; }
        foreach ($decls as $line) { $out .= $line . "\n"; }
        if ($this->needsCliArgv) {
            // linkonce_odr: argc/argv are SET by @main in user.o and READ by
            // CLI helpers that may live in stdlib.o; per-.o internal copies
            // would leave stdlib reading 0/null. Coalesce to one address.
            $out .= "@__manticore_argc = linkonce_odr global i64 0\n";
            $out .= "@__manticore_argv = linkonce_odr global ptr null\n";
            $out .= "define i64 @manticore_cli_argc() {\nentry:\n";
            $out .= "  %a = load i64, ptr @__manticore_argc\n";
            $out .= "  ret i64 %a\n}\n";
            $out .= "define ptr @manticore_cli_argv(i64 %i) {\nentry:\n";
            $out .= "  %n = load i64, ptr @__manticore_argc\n";
            $out .= "  %lo = icmp slt i64 %i, 0\n";
            $out .= "  %hi = icmp sge i64 %i, %n\n";
            $out .= "  %oob = or i1 %lo, %hi\n";
            $out .= "  br i1 %oob, label %null, label %ok\n";
            $out .= "null:\n  ret ptr null\n";
            $out .= "ok:\n";
            $out .= "  %v = load ptr, ptr @__manticore_argv\n";
            $out .= "  %g = getelementptr inbounds ptr, ptr %v, i64 %i\n";
            $out .= "  %e = load ptr, ptr %g\n";
            $out .= "  ret ptr %e\n}\n";
        }
        if ($this->needsStdStreams) {
            // STDIN/STDOUT/STDERR resolve to libc's own FILE* globals so a
            // fwrite(STDOUT, ...) shares the SAME buffer as echo (printf →
            // stdout) and ordering matches PHP. The symbol names differ per
            // libc: glibc exports `stdin/stdout/stderr`; the Apple/BSD libc
            // exports `__stdinp/__stdoutp/__stderrp`. We pick by host_os() — a
            // runtime call (NOT the PHP_OS constant, which would fold via
            // host_os() at lower time and crash the Zend cold-seed). This block
            // only runs when the program uses a stream; the compiler's own src/
            // never does, so the seed never executes it under Zend.
            $darwin = \strpos(\Manticore\host_os(), 'Darwin') !== false;
            $syms = $darwin
                ? ['stdin' => '__stdinp', 'stdout' => '__stdoutp', 'stderr' => '__stderrp']
                : ['stdin' => 'stdin', 'stdout' => 'stdout', 'stderr' => 'stderr'];
            foreach ($syms as $name => $sym) {
                $out .= '@' . $sym . " = external global ptr\n";
                $out .= 'define ptr @manticore_' . $name . "() {\nentry:\n";
                $out .= '  %p = load ptr, ptr @' . $sym . "\n";
                $out .= "  ret ptr %p\n}\n";
            }
        }
        $out .= $this->profileRuntime();
        $out .= $this->allocRuntime();
        if ($this->needsFloatStr) {
            $out .= $this->floatToStrImpl('@__mir_float_to_str', '@__mir_str_alloc');
            if ($this->needsArena) {
                $out .= $this->floatToStrImpl('@__mir_float_to_str_arena', '@__mir_str_alloc_arena');
            }
        }
        if ($this->needsFloatShortest) {
            $out .= $this->floatShortestImpl();
        }
        // tagged_to_str (mixed→string) calls @__mir_int_to_str for the int
        // tag, so pull the int-to-string helper in whenever it's emitted.
        if ($this->needsConcat || $this->needsIntStr || $this->needsTaggedToStr) {
            $out .= $this->intToStrRuntime();
        }
        // box_int/unbox_int: needed by the full tagged runtime AND by every
        // tagged render helper (asint arm calls unbox_int). Emit once, under the
        // union of those gates, else a helper links an undefined ref (→ identity
        // stub → boxed bits printed).
        if ($this->needsTagged || $this->needsTaggedToStr || $this->needsTaggedToInt
            || $this->needsTaggedToFloat || $this->needsTaggedEcho || $this->needsIntStr) {
            $out .= $this->boxIntRuntime();
        }
        if ($this->needsTagged) {
            $out .= $this->taggedRuntime();
        }
        if ($this->needsTaggedEcho) {
            $out .= $this->taggedEchoRuntime();
        }
        if ($this->needsTaggedToStr) {
            $out .= $this->taggedToStrRuntime();
        }
        if ($this->needsImplodeCell) {
            $out .= $this->implodeCellRuntime();
        }
        if ($this->needsTaggedToInt) {
            $out .= $this->taggedToIntRuntime();
        }
        if ($this->needsTaggedToFloat) {
            $out .= $this->taggedToFloatRuntime();
        }
        if ($this->needsTaggedCompare) {
            $out .= $this->taggedCompareRuntime();
        }
        if ($this->needsTaggedEq) {
            $out .= $this->taggedEqRuntime();
        }
        if ($this->needsTaggedArith) {
            $out .= $this->taggedArithRuntime();
        }
        if ($this->needsTaggedTruthy) {
            $out .= $this->taggedTruthyRuntime();
        }
        if ($this->needsConcat) {
            $out .= $this->concatRuntime();
        }
        $out .= $this->stringBuiltinRuntime();
        // Unified PhpArray runtime (docs/bootstrap/16) — the ONLY array
        // runtime; driven by a BareHost (libc malloc, no arena / rc-trace
        // / profile). Render functions only; the external declares above
        // own the libc symbols.
        $arrMod = new LlvmModule('mir_array_rt');
        (new UnifiedArrayRuntime($arrMod, new BareHost()))->emitAll();
        $out .= "\n" . $arrMod->emitFunctionsOnly();
        if ($this->needsCellKey) {
            $out .= $this->cellKeyRuntime();
        }
        $out .= $this->emitEnumTables();
        $out .= "\n";
        return $out;
    }

    /** Backing kind via a typed param (self-host slot offset). */
    private function edBacking(\Compile\Mir\EnumDef $ed): string
    {
        return $ed->backing;
    }

    /**
     * Per-enum globals: a `<Enum>__names` array of case-name string
     * ptrs (indexed by ordinal), and — for a backed enum — a
     * `<Enum>__values` array (i64 for int backing, ptr for string).
     */
    private function emitEnumTables(): string
    {
        $out = '';
        foreach ($this->enums as $name => $ed) {
            $n = \count($ed->caseNames);
            // case-name strings + names table
            $namePtrs = [];
            $i = 0;
            foreach ($ed->caseNames as $cn) {
                $sym = '@' . $name . '__nm_' . (string)$i;
                $out .= $this->strGlobalDef($sym, $cn);
                $namePtrs[] = 'ptr ' . $this->strSymBytes($sym);
                $i = $i + 1;
            }
            $out .= '@' . $name . '__names = private unnamed_addr constant ['
                  . (string)$n . ' x ptr] [' . \implode(', ', $namePtrs) . "]\n";
            $backing = $this->edBacking($ed);
            if ($backing === 'int') {
                $vals = [];
                foreach ($ed->intValues as $v) { $vals[] = 'i64 ' . (string)$v; }
                $out .= '@' . $name . '__values = private unnamed_addr constant ['
                      . (string)$n . ' x i64] [' . \implode(', ', $vals) . "]\n";
            } elseif ($backing === 'string') {
                $vptrs = [];
                $j = 0;
                foreach ($ed->strValues as $sv) {
                    $sym = '@' . $name . '__vs_' . (string)$j;
                    $out .= $this->strGlobalDef($sym, $sv);
                    $vptrs[] = 'ptr ' . $this->strSymBytes($sym);
                    $j = $j + 1;
                }
                $out .= '@' . $name . '__values = private unnamed_addr constant ['
                      . (string)$n . ' x ptr] [' . \implode(', ', $vptrs) . "]\n";
            }
            $out .= $this->emitEnumCellSingletons($name, $ed);
        }
        return $out;
    }

    /**
     * Per-enum-case SINGLETON objects, so an enum case boxed into a `mixed`/cell
     * (a heterogeneous array, a `mixed` var_dump arg) round-trips with its class
     * identity intact — box_object of the raw ORDINAL would tag a tiny int as a
     * pointer, and every generic object consumer (var_dump / ===) then derefs it
     * → SIGSEGV / wrong compare. Each singleton mimics the object layout so the
     * normal object machinery works uniformly:
     *   data-8 : header sentinel 0 (NOT RC_TAG_MAGIC → cell_drop / rc ops SKIP it;
     *            the case is a `constant`, immortal, never rc-touched)
     *   data+0 : class descriptor ptr ({class_id, drop=null}) — instanceof /
     *            __mir_enum_name read class_id THROUGH it
     *   data+8 : rc (unused)
     *   data+16: ordinal
     * `<Enum>__cases[ordinal]` is the boxed-object payload ptr (data ptr), and
     * `<Enum>__fqns[ordinal]` the "<Enum>::<Case>" string for var_dump.
     */
    private function emitEnumCellSingletons(string $name, \Compile\Mir\EnumDef $ed): string
    {
        $cid = (string)$ed->classId;
        $out = '';
        // Descriptor — reuse the class descriptor if a method-enum already
        // registered one (dropRuntime emits `@__mir_cd_<id>` for it); else emit.
        if (!isset($this->classes[$name])) {
            $out .= '@__mir_cd_' . $cid . ' = linkonce_odr global { i64, ptr } { i64 '
                  . $cid . ", ptr null }\n";
        }
        $descI = 'ptrtoint (ptr @__mir_cd_' . $cid . ' to i64)';
        $n = \count($ed->caseNames);
        $dataPtrs = [];
        $fqnPtrs = [];
        $i = 0;
        foreach ($ed->caseNames as $cn) {
            $sym = '@' . $name . '__case_' . (string)$i;
            $out .= $sym . ' = linkonce_odr constant { i64, i64, i64, i64 } { i64 0, i64 '
                  . $descI . ', i64 0, i64 ' . (string)$i . " }\n";
            $dataPtrs[] = 'i64 ptrtoint (ptr getelementptr (i8, ptr ' . $sym . ', i64 8) to i64)';
            $fq = '@' . $name . '__fqn_' . (string)$i;
            $out .= $this->strGlobalDef($fq, $name . '::' . $cn);
            $fqnPtrs[] = 'ptr ' . $this->strSymBytes($fq);
            $i = $i + 1;
        }
        $out .= '@' . $name . '__cases = linkonce_odr constant [' . (string)$n
              . ' x i64] [' . \implode(', ', $dataPtrs) . "]\n";
        $out .= '@' . $name . '__fqns = linkonce_odr constant [' . (string)$n
              . ' x ptr] [' . \implode(', ', $fqnPtrs) . "]\n";
        return $out;
    }

    /**
     * String runtime for `.`:
     *   __mir_int_to_str(i64) -> ptr   — decimal text via snprintf
     *   __mir_concat(ptr,ptr) -> ptr   — strlen+malloc+memcpy×2 (+NUL)
     * Keys/strings are NUL-terminated C strings, matching echo (%s)
     * and the assoc strcmp keys.
     */
    /**
     * NaN-boxing helpers (subset). 48-bit payload, 4-bit tag at bit 48,
     * NaN header 0xFFF0000000000000. TAG_INT=1. Mirrors the AST
     * backend's TaggedValues so box/unbox/tag round-trip identically.
     */
    /**
     * `__manticore_box_int` / `__manticore_unbox_int` — int↔cell boxing. An int
     * in [-2^47, 2^47) fits the 48-bit payload (tag INT=1); a WIDER int is
     * heap-boxed (malloc 8, store the full i64) and tagged BIGINT=5 (tagBits
     * 0xFFF5.. = -3096224743817216), so a 64-bit int survives a cell round-trip.
     * The 8-byte cell is immortal (ints carry no rc) — a bounded leak for the
     * rare large-int-in-cell case. Emitted under a broad gate (boxIntRuntime is
     * called by the render helpers too — they call unbox_int for the int arm).
     */
    private function boxIntRuntime(): string
    {
        $out  = "\ndefine i64 @__manticore_box_int(i64 %v) {\n";
        $out .= "entry:\n";
        $out .= "  %s = shl i64 %v, 16\n";
        $out .= "  %se = ashr i64 %s, 16\n";
        $out .= "  %fits = icmp eq i64 %se, %v\n";
        $out .= "  br i1 %fits, label %inl, label %heap\n";
        $out .= "inl:\n";
        $out .= "  %m = and i64 %v, 281474976710655\n";
        $out .= "  %b = or i64 %m, -4222124650659840\n";
        $out .= "  ret i64 %b\n";
        $out .= "heap:\n";
        $out .= "  %p = call ptr @malloc(i64 8)\n";
        $out .= "  store i64 %v, ptr %p\n";
        $out .= "  %pi = ptrtoint ptr %p to i64\n";
        $out .= "  %pm = and i64 %pi, 281474976710655\n";
        $out .= "  %pb = or i64 %pm, -3096224743817216\n";
        $out .= "  ret i64 %pb\n";
        $out .= "}\n";
        $out .= "define i64 @__manticore_unbox_int(i64 %v) {\n";
        $out .= "entry:\n";
        $out .= "  %sh = lshr i64 %v, 48\n";
        $out .= "  %nib = and i64 %sh, 15\n";
        $out .= "  %big = icmp eq i64 %nib, 5\n";
        $out .= "  br i1 %big, label %fromheap, label %inl\n";
        $out .= "fromheap:\n";
        $out .= "  %pm = and i64 %v, 281474976710655\n";
        $out .= "  %hp = inttoptr i64 %pm to ptr\n";
        $out .= "  %hv = load i64, ptr %hp\n";
        $out .= "  ret i64 %hv\n";
        $out .= "inl:\n";
        $out .= "  %s = shl i64 %v, 16\n";
        $out .= "  %r = ashr i64 %s, 16\n";
        $out .= "  ret i64 %r\n";
        $out .= "}\n";
        return $out;
    }

    private function taggedRuntime(): string
    {
        // PAYLOAD_MASK = 0xFFFFFFFFFFFF = 281474976710655
        // tagBits(int) = (1<<48)|0xFFF0000000000000 = -4222124650659840
        // box_int/unbox_int live in boxIntRuntime() (broader gate).
        $out = '';
        // __manticore_tag: a tagged cell has header bits > 0xFFF0000000000000
        // (int=0xFFF1 … object=0xFFF8); anything else (a finite double, ±Inf,
        // canonical NaN) is a raw double → synthetic FLOAT tag 6.
        $out .= "define i64 @__manticore_tag(i64 %v) {\n";
        $out .= "entry:\n";
        $out .= "  %istag = icmp ugt i64 %v, -4503599627370496\n";
        $out .= "  %s = lshr i64 %v, 48\n";
        $out .= "  %nibr = and i64 %s, 15\n";
        // BIGINT (nibble 5, a heap-boxed int) is an INT for every tag consumer.
        $out .= "  %is5 = icmp eq i64 %nibr, 5\n";
        $out .= "  %nib = select i1 %is5, i64 1, i64 %nibr\n";
        $out .= "  %t = select i1 %istag, i64 %nib, i64 6\n";
        $out .= "  ret i64 %t\n";
        $out .= "}\n";
        // box_bool: (v & 1) | tagBits(BOOL=2)
        $out .= "define i64 @__manticore_box_bool(i64 %v) {\n";
        $out .= "entry:\n";
        $out .= "  %m = and i64 %v, 1\n";
        $out .= "  %b = or i64 %m, -3940649673949184\n";
        $out .= "  ret i64 %b\n";
        $out .= "}\n";
        // box_null: pure header + tag(NULL=3)
        $out .= "define i64 @__manticore_box_null() {\n";
        $out .= "entry:\n";
        $out .= "  ret i64 -3659174697238528\n";
        $out .= "}\n";
        // box_ptr: (ptrtoint(p) & PAYLOAD_MASK) | tagBits(PTR=4). A 0 pointer
        // can only be a `?string` null (a real string ptr is never 0) → box as
        // NULL so var_dump / echo / json_encode of a null `?T` don't deref 0.
        $out .= "define i64 @__manticore_box_ptr(ptr %p) {\n";
        $out .= "entry:\n";
        $out .= "  %i = ptrtoint ptr %p to i64\n";
        $out .= "  %nz = icmp eq i64 %i, 0\n";
        $out .= "  br i1 %nz, label %nul, label %box\n";
        $out .= "nul:\n";
        $out .= "  ret i64 -3659174697238528\n";
        $out .= "box:\n";
        $out .= "  %m = and i64 %i, 281474976710655\n";
        $out .= "  %b = or i64 %m, -3377699720527872\n";
        $out .= "  ret i64 %b\n";
        $out .= "}\n";
        // box_float: canonical NaN-boxing — a real double IS its own 64 bits
        // (lossless), stored raw. A NaN is canonicalized to the quiet-NaN
        // 0x7FF8000000000000 so it can never collide with a tagged cell (whose
        // header is 0xFFF1..0xFFF8, all > 0xFFF0000000000000). Tag dispatch
        // (see __manticore_tag) treats any i64 NOT in the tagged range as a
        // raw double → synthetic FLOAT tag 6.
        $out .= "define i64 @__manticore_box_float(double %f) {\n";
        $out .= "entry:\n";
        $out .= "  %i = bitcast double %f to i64\n";
        $out .= "  %isnan = fcmp uno double %f, %f\n";
        $out .= "  %b = select i1 %isnan, i64 9221120237041090560, i64 %i\n";
        $out .= "  ret i64 %b\n";
        $out .= "}\n";
        // box_array: (ptrtoint(p) & PAYLOAD_MASK) | tagBits(ARRAY=7). 0 → NULL
        // (a `?array` null; a real array ptr is never 0).
        $out .= "define i64 @__manticore_box_array(ptr %p) {\n";
        $out .= "entry:\n";
        $out .= "  %i = ptrtoint ptr %p to i64\n";
        $out .= "  %nz = icmp eq i64 %i, 0\n";
        $out .= "  br i1 %nz, label %nul, label %box\n";
        $out .= "nul:\n";
        $out .= "  ret i64 -3659174697238528\n";
        $out .= "box:\n";
        $out .= "  %m = and i64 %i, 281474976710655\n";
        $out .= "  %b = or i64 %m, -2533274790395904\n";
        $out .= "  ret i64 %b\n";
        $out .= "}\n";
        // box_object: (ptrtoint(p) & PAYLOAD_MASK) | tagBits(OBJECT=8). 0 → NULL
        // (a `?Obj` null; a real object ptr is never 0).
        $out .= "define i64 @__manticore_box_object(ptr %p) {\n";
        $out .= "entry:\n";
        $out .= "  %i = ptrtoint ptr %p to i64\n";
        $out .= "  %nz = icmp eq i64 %i, 0\n";
        $out .= "  br i1 %nz, label %nul, label %box\n";
        $out .= "nul:\n";
        $out .= "  ret i64 -3659174697238528\n";
        $out .= "box:\n";
        $out .= "  %m = and i64 %i, 281474976710655\n";
        $out .= "  %b = or i64 %m, -2251799813685248\n";
        $out .= "  ret i64 %b\n";
        $out .= "}\n";
        return $out;
    }

    /**
     * Emit IR computing the 4-bit cell tag of $v into a fresh SSA reg, with
     * canonical-NaN-boxing semantics: an i64 NOT in the tagged range
     * (> 0xFFF0000000000000) is a raw double → synthetic FLOAT tag 6. Mirrors
     * {@see taggedRuntime}'s `__manticore_tag` for the inline cell-tag checks
     * (instanceof / `=== null` / `=== false` / string-compare) so a raw-double
     * cell is never misread as a tagged value of a colliding nibble. The
     * computed tag SSA reg is left in {@see $cellTagReg} (no by-ref out-param —
     * that pattern miscompiles under self-host).
     */
    private function cellTagIr(string $v): string
    {
        $istag = $this->allocSsa();
        $ts = $this->allocSsa();
        $nib = $this->allocSsa();
        $tag = $this->allocSsa();
        $this->cellTagReg = $tag;
        $nibr = $this->allocSsa();
        $is5 = $this->allocSsa();
        return '  ' . $istag . ' = icmp ugt i64 ' . $v . ", -4503599627370496\n"
            . '  ' . $ts . ' = lshr i64 ' . $v . ", 48\n"
            . '  ' . $nibr . ' = and i64 ' . $ts . ", 15\n"
            . '  ' . $is5 . ' = icmp eq i64 ' . $nibr . ", 5\n"
            . '  ' . $nib . ' = select i1 ' . $is5 . ', i64 1, i64 ' . $nibr . "\n"
            . '  ' . $tag . ' = select i1 ' . $istag . ', i64 ' . $nib . ", i64 6\n";
    }

    /**
     * Runtime dispatch for a `mixed`/cell array key (e.g. ArrayAccess
     * `offsetGet/Set` with a `mixed $key`). A PHP array key is int OR string,
     * decided at runtime; the static get/set/isset/unset helpers are typed, so
     * branch on the cell tag (PTR=4 → string key, else int) and route to the
     * matching typed helper. Tag/unbox math is inlined so these never depend on
     * the `needsTagged`-gated box helpers — only the always-emitted array
     * runtime. Keys carry no rc here (offset interning is the array's concern).
     */
    private function cellKeyRuntime(): string
    {
        // PAYLOAD_MASK = 281474976710655; PTR/string tag = 4.
        $out  = "\ndefine ptr @__mir_array_set_cell(ptr %arr, i64 %k, i64 %val) {\n";
        $out .= "entry:\n";
        $out .= "  %istag = icmp ugt i64 %k, -4503599627370496\n";
        $out .= "  %ts = lshr i64 %k, 48\n  %nib = and i64 %ts, 15\n";
        $out .= "  %tag = select i1 %istag, i64 %nib, i64 6\n";
        $out .= "  %isstr = icmp eq i64 %tag, 4\n";
        $out .= "  br i1 %isstr, label %s, label %i\n";
        $out .= "s:\n  %pp = and i64 %k, 281474976710655\n  %kp = inttoptr i64 %pp to ptr\n";
        $out .= "  %r1 = call ptr @__mir_array_set_str(ptr %arr, ptr %kp, i64 %val, i64 0, i64 0)\n  ret ptr %r1\n";
        $out .= "i:\n  %sh = shl i64 %k, 16\n  %ki = ashr i64 %sh, 16\n";
        $out .= "  %r2 = call ptr @__mir_array_set_int(ptr %arr, i64 %ki, i64 %val)\n  ret ptr %r2\n}\n";

        $out .= "define i64 @__mir_array_get_cell(ptr %arr, i64 %k) {\n";
        $out .= "entry:\n";
        $out .= "  %istag = icmp ugt i64 %k, -4503599627370496\n";
        $out .= "  %ts = lshr i64 %k, 48\n  %nib = and i64 %ts, 15\n";
        $out .= "  %tag = select i1 %istag, i64 %nib, i64 6\n";
        $out .= "  %isstr = icmp eq i64 %tag, 4\n";
        $out .= "  br i1 %isstr, label %s, label %i\n";
        $out .= "s:\n  %pp = and i64 %k, 281474976710655\n  %kp = inttoptr i64 %pp to ptr\n";
        $out .= "  %r1 = call i64 @__mir_array_get_str(ptr %arr, ptr %kp, i64 0, i64 0)\n  ret i64 %r1\n";
        $out .= "i:\n  %sh = shl i64 %k, 16\n  %ki = ashr i64 %sh, 16\n";
        $out .= "  %r2 = call i64 @__mir_array_get_int(ptr %arr, i64 %ki)\n  ret i64 %r2\n}\n";

        $out .= "define i64 @__mir_array_isset_cell(ptr %arr, i64 %k) {\n";
        $out .= "entry:\n";
        $out .= "  %istag = icmp ugt i64 %k, -4503599627370496\n";
        $out .= "  %ts = lshr i64 %k, 48\n  %nib = and i64 %ts, 15\n";
        $out .= "  %tag = select i1 %istag, i64 %nib, i64 6\n";
        $out .= "  %isstr = icmp eq i64 %tag, 4\n";
        $out .= "  br i1 %isstr, label %s, label %i\n";
        $out .= "s:\n  %pp = and i64 %k, 281474976710655\n  %kp = inttoptr i64 %pp to ptr\n";
        $out .= "  %r1 = call i64 @__mir_array_isset_str(ptr %arr, ptr %kp, i64 0, i64 0)\n  ret i64 %r1\n";
        $out .= "i:\n  %sh = shl i64 %k, 16\n  %ki = ashr i64 %sh, 16\n";
        $out .= "  %r2 = call i64 @__mir_array_isset_int(ptr %arr, i64 %ki)\n  ret i64 %r2\n}\n";

        $out .= "define void @__mir_array_unset_cell(ptr %arr, i64 %k) {\n";
        $out .= "entry:\n";
        $out .= "  %istag = icmp ugt i64 %k, -4503599627370496\n";
        $out .= "  %ts = lshr i64 %k, 48\n  %nib = and i64 %ts, 15\n";
        $out .= "  %tag = select i1 %istag, i64 %nib, i64 6\n";
        $out .= "  %isstr = icmp eq i64 %tag, 4\n";
        $out .= "  br i1 %isstr, label %s, label %i\n";
        $out .= "s:\n  %pp = and i64 %k, 281474976710655\n  %kp = inttoptr i64 %pp to ptr\n";
        $out .= "  call void @__mir_array_unset_str(ptr %arr, ptr %kp)\n  ret void\n";
        $out .= "i:\n  %sh = shl i64 %k, 16\n  %ki = ashr i64 %sh, 16\n";
        $out .= "  call void @__mir_array_unset_int(ptr %arr, i64 %ki)\n  ret void\n}\n";
        return $out;
    }

    /**
     * `echo` of a NaN-boxed cell — dispatch on the 4-bit tag and print
     * with PHP echo semantics: int decimal, float %g, true → "1",
     * false / null → nothing, ptr (string) → %s.
     */
    private function taggedEchoRuntime(): string
    {
        $out  = "\n@.tagstr.true = private unnamed_addr constant [2 x i8] c\"1\\00\", align 1\n";
        $out .= "@.tagstr.array = private unnamed_addr constant [6 x i8] c\"Array\\00\", align 1\n";
        $out .= "define void @__manticore_echo_tagged(i64 %v) {\n";
        $out .= "entry:\n";
        $out .= "  %istag = icmp ugt i64 %v, -4503599627370496\n";
        $out .= "  %ts = lshr i64 %v, 48\n";
        $out .= "  %nib = and i64 %ts, 15\n";
        $out .= "  %tag = select i1 %istag, i64 %nib, i64 6\n";
        $out .= "  switch i64 %tag, label %asint [\n";
        $out .= "    i64 2, label %asbool\n";
        $out .= "    i64 3, label %asnull\n";
        $out .= "    i64 4, label %asptr\n";
        $out .= "    i64 6, label %asfloat\n";
        $out .= "    i64 7, label %asarray\n";
        $out .= "  ]\n";
        $out .= "asarray:\n";
        $out .= "  call i32 (ptr, ...) @printf(ptr @.fmt.s, ptr @.tagstr.array)\n";
        $out .= "  ret void\n";
        $out .= "asint:\n";
        $out .= "  %i = call i64 @__manticore_unbox_int(i64 %v)\n";
        $out .= "  call i32 (ptr, ...) @printf(ptr @.fmt.d, i64 %i)\n";
        $out .= "  ret void\n";
        $out .= "asbool:\n";
        $out .= "  %bb = and i64 %v, 1\n";
        $out .= "  %istrue = icmp ne i64 %bb, 0\n";
        $out .= "  br i1 %istrue, label %bt, label %bdone\n";
        $out .= "bt:\n";
        $out .= "  call i32 (ptr, ...) @printf(ptr @.fmt.s, ptr @.tagstr.true)\n";
        $out .= "  ret void\n";
        $out .= "bdone:\n";
        $out .= "  ret void\n";
        $out .= "asnull:\n";
        $out .= "  ret void\n";
        $out .= "asptr:\n";
        $out .= "  %pp = and i64 %v, 281474976710655\n";
        $out .= "  %p = inttoptr i64 %pp to ptr\n";
        $out .= "  call i32 (ptr, ...) @printf(ptr @.fmt.s, ptr %p)\n";
        $out .= "  ret void\n";
        $out .= "asfloat:\n";
        $out .= "  %fd = bitcast i64 %v to double\n";
        $out .= "  call i32 (ptr, ...) @printf(ptr @.fmt.pg, double %fd)\n";
        $out .= "  ret void\n";
        $out .= "}\n";
        return $out;
    }

    /**
     * `(string)` of a NaN-boxed cell → a fresh NUL-terminated string.
     * int → decimal, float → %.14g, true → "1", false/null → "", ptr →
     * the string itself, array → "Array".
     */
    private function taggedToStrRuntime(): string
    {
        $this->needsIntStr = true;
        $this->libcExtra['snprintf'] = 'declare i32 @snprintf(ptr, i64, ptr, ...)';
        $this->libcExtra['malloc'] = 'declare ptr @malloc(i64)';
        // Headered so a (string)-cast result (which may be one of these
        // literals or a heap int/float string) is safe to retain/release.
        $out  = "\n" . $this->strGlobalDef('@.ts.one', '1');
        $out .= $this->strGlobalDef('@.ts.empty', '');
        $out .= $this->strGlobalDef('@.ts.array', 'Array');
        $out .= "define ptr @__manticore_tagged_to_str(i64 %v) {\n";
        $out .= "entry:\n";
        $out .= "  %istag = icmp ugt i64 %v, -4503599627370496\n";
        $out .= "  %ts = lshr i64 %v, 48\n";
        $out .= "  %nib = and i64 %ts, 15\n";
        $out .= "  %tag = select i1 %istag, i64 %nib, i64 6\n";
        $out .= "  switch i64 %tag, label %asint [\n";
        $out .= "    i64 2, label %asbool\n";
        $out .= "    i64 3, label %asnull\n";
        $out .= "    i64 4, label %asptr\n";
        $out .= "    i64 6, label %asfloat\n";
        $out .= "    i64 7, label %asarray\n";
        $out .= "  ]\n";
        $out .= "asint:\n";
        $out .= "  %i = call i64 @__manticore_unbox_int(i64 %v)\n";
        $out .= "  %is = call ptr @__mir_int_to_str(i64 %i)\n";
        $out .= "  ret ptr %is\n";
        $out .= "asbool:\n";
        $out .= "  %bb = and i64 %v, 1\n";
        $out .= "  %istrue = icmp ne i64 %bb, 0\n";
        $out .= "  %bsel = select i1 %istrue, ptr " . $this->strSymBytes('@.ts.one')
              . ", ptr " . $this->strSymBytes('@.ts.empty') . "\n";
        $out .= "  ret ptr %bsel\n";
        $out .= "asnull:\n";
        $out .= "  ret ptr " . $this->strSymBytes('@.ts.empty') . "\n";
        $out .= "asptr:\n";
        $out .= "  %pp = and i64 %v, 281474976710655\n";
        $out .= "  %pptr = inttoptr i64 %pp to ptr\n";
        $out .= "  ret ptr %pptr\n";
        $out .= "asfloat:\n";
        $out .= "  %fd = bitcast i64 %v to double\n";
        $out .= "  %fbuf = call ptr @__mir_str_alloc(i64 32)\n";
        $out .= "  %fn = call i32 (ptr, i64, ptr, ...) @snprintf(ptr %fbuf, i64 32, ptr @.fmt.pg, double %fd)\n";
        $out .= "  %fnl = sext i32 %fn to i64\n";
        $out .= "  call void @__mir_str_set_len(ptr %fbuf, i64 %fnl)\n";
        $out .= "  ret ptr %fbuf\n";
        $out .= "asarray:\n";
        $out .= "  ret ptr " . $this->strSymBytes('@.ts.array') . "\n";
        $out .= "}\n";
        return $out;
    }

    /**
     * `__mir_array_implode_cell(sep, arr) -> ptr` — join a cell-array (every
     * element NaN-boxed) by converting each element to a string via
     * `__manticore_tagged_to_str`. Mirrors __mir_array_implode but for a
     * non-string element vec (int/float/mixed): biImplode boxes the vec into a
     * cell-array first. Two passes (sum lengths, then copy with separators).
     */
    private function implodeCellRuntime(): string
    {
        $this->libcExtra['memcpy'] = 'declare ptr @memcpy(ptr, ptr, i64)';
        $this->libcExtra['strlen'] = 'declare i64 @strlen(ptr)';
        $out  = "\ndefine ptr @__mir_array_implode_cell(ptr %sep, ptr %arr) {\n";
        $out .= "entry:\n";
        $out .= "  %len = load i64, ptr %arr\n";
        $out .= "  %ez = icmp sle i64 %len, 0\n";
        $out .= "  br i1 %ez, label %empty, label %init\n";
        $out .= "empty:\n  ret ptr " . $this->strSymBytes('@.ts.empty') . "\n";
        $out .= "init:\n";
        // Header length (binary-safe), NOT libc strlen: tagged_to_str returns the
        // RAW element string ptr for a string cell (no copy), which may carry no
        // trailing NUL — libc strlen over-reads, and since the sizing and copy
        // passes strlen independently, an intervening alloc can make el2 > el and
        // overrun the buffer (layout-flaky heap corruption). See __mir_array_implode.
        $out .= "  %seplen = call i64 @__mir_strlen(ptr %sep)\n";
        $out .= "  %accp = alloca i64\n  store i64 0, ptr %accp\n";
        $out .= "  %ip = alloca i64\n  store i64 0, ptr %ip\n  br label %sumc\n";
        $out .= "sumc:\n  %i = load i64, ptr %ip\n  %sd = icmp slt i64 %i, %len\n";
        $out .= "  br i1 %sd, label %sumb, label %alloc\n";
        $out .= "sumb:\n";
        $out .= "  %ev = call i64 @__mir_array_value_at(ptr %arr, i64 %i)\n";
        $out .= "  %es = call ptr @__manticore_tagged_to_str(i64 %ev)\n";
        $out .= "  %el = call i64 @__mir_strlen(ptr %es)\n";
        $out .= "  %a = load i64, ptr %accp\n  %a2 = add i64 %a, %el\n  store i64 %a2, ptr %accp\n";
        $out .= "  %i2 = add i64 %i, 1\n  store i64 %i2, ptr %ip\n  br label %sumc\n";
        $out .= "alloc:\n";
        $out .= "  %acc = load i64, ptr %accp\n  %lm1 = sub i64 %len, 1\n";
        $out .= "  %sb = mul i64 %seplen, %lm1\n  %t = add i64 %acc, %sb\n  %sz = add i64 %t, 1\n";
        $out .= "  %buf = call ptr @__mir_str_alloc(i64 %sz)\n  store i8 0, ptr %buf\n";
        $out .= "  store i64 0, ptr %ip\n  %wp = alloca i64\n  store i64 0, ptr %wp\n  br label %cpc\n";
        $out .= "cpc:\n  %j = load i64, ptr %ip\n  %cd = icmp slt i64 %j, %len\n";
        $out .= "  br i1 %cd, label %cpb, label %fin\n";
        $out .= "cpb:\n  %first = icmp eq i64 %j, 0\n  br i1 %first, label %nosep, label %dosep\n";
        $out .= "dosep:\n  %w0 = load i64, ptr %wp\n";
        $out .= "  %dst0 = getelementptr inbounds i8, ptr %buf, i64 %w0\n";
        $out .= "  call ptr @memcpy(ptr %dst0, ptr %sep, i64 %seplen)\n";
        $out .= "  %w0b = add i64 %w0, %seplen\n  store i64 %w0b, ptr %wp\n  br label %nosep\n";
        $out .= "nosep:\n";
        $out .= "  %ev2 = call i64 @__mir_array_value_at(ptr %arr, i64 %j)\n";
        $out .= "  %es2 = call ptr @__manticore_tagged_to_str(i64 %ev2)\n";
        $out .= "  %el2 = call i64 @__mir_strlen(ptr %es2)\n";
        $out .= "  %w1 = load i64, ptr %wp\n";
        $out .= "  %dst1 = getelementptr inbounds i8, ptr %buf, i64 %w1\n";
        $out .= "  call ptr @memcpy(ptr %dst1, ptr %es2, i64 %el2)\n";
        $out .= "  %w2 = add i64 %w1, %el2\n  store i64 %w2, ptr %wp\n";
        $out .= "  %j2 = add i64 %j, 1\n  store i64 %j2, ptr %ip\n  br label %cpc\n";
        $out .= "fin:\n  %wf = load i64, ptr %wp\n";
        $out .= "  %nulp = getelementptr inbounds i8, ptr %buf, i64 %wf\n";
        $out .= "  store i8 0, ptr %nulp\n  ret ptr %buf\n}\n";
        return $out;
    }

    /**
     * `(int)$cell` — convert a NaN-boxed value to i64 by tag: int → the payload
     * int, bool → 0/1, null → 0, string → strtol(base 10), float → truncate,
     * array → 1 if non-empty else 0 (PHP semantics). Objects don't reach here
     * (PHP forbids the cast). Mirrors {@see taggedToStrRuntime}.
     */
    private function taggedToIntRuntime(): string
    {
        $this->needsStrtol = true;
        $out  = "\ndefine i64 @__manticore_tagged_to_int(i64 %v) {\n";
        $out .= "entry:\n";
        $out .= "  %istag = icmp ugt i64 %v, -4503599627370496\n";
        $out .= "  %ts = lshr i64 %v, 48\n";
        $out .= "  %nib = and i64 %ts, 15\n";
        $out .= "  %tag = select i1 %istag, i64 %nib, i64 6\n";
        $out .= "  switch i64 %tag, label %asint [\n";
        $out .= "    i64 2, label %asbool\n";
        $out .= "    i64 3, label %asnull\n";
        $out .= "    i64 4, label %asstr\n";
        $out .= "    i64 6, label %asfloat\n";
        $out .= "    i64 7, label %asarray\n";
        $out .= "  ]\n";
        $out .= "asint:\n";
        $out .= "  %i = call i64 @__manticore_unbox_int(i64 %v)\n";
        $out .= "  ret i64 %i\n";
        $out .= "asbool:\n";
        $out .= "  %bb = and i64 %v, 1\n";
        $out .= "  ret i64 %bb\n";
        $out .= "asnull:\n";
        $out .= "  ret i64 0\n";
        $out .= "asstr:\n";
        $out .= "  %sp = and i64 %v, 281474976710655\n";
        $out .= "  %sptr = inttoptr i64 %sp to ptr\n";
        $out .= "  %sv = call i64 @strtol(ptr %sptr, ptr null, i32 10)\n";
        $out .= "  ret i64 %sv\n";
        $out .= "asfloat:\n";
        $out .= "  %fd = bitcast i64 %v to double\n";
        $out .= "  %fi = fptosi double %fd to i64\n";
        $out .= "  ret i64 %fi\n";
        $out .= "asarray:\n";
        $out .= "  %ap = and i64 %v, 281474976710655\n";
        $out .= "  %aptr = inttoptr i64 %ap to ptr\n";
        $out .= "  %alen = load i64, ptr %aptr\n";
        $out .= "  %ane = icmp ne i64 %alen, 0\n";
        $out .= "  %az = zext i1 %ane to i64\n";
        $out .= "  ret i64 %az\n";
        $out .= "}\n";
        return $out;
    }

    /**
     * NaN-boxed cell → double (numeric context for float arithmetic / `/`).
     * int → sitofp, bool → 0/1, null → 0.0, string → strtod, float → its bits,
     * array → non-empty?1:0. Mirrors {@see taggedToIntRuntime} but yields a
     * double so `$x / 2` and float arithmetic over a cell operand are exact
     * instead of bitcasting the tagged bits.
     */
    private function taggedToFloatRuntime(): string
    {
        $out  = "\ndefine double @__manticore_tagged_to_double(i64 %v) {\n";
        $out .= "entry:\n";
        $out .= "  %istag = icmp ugt i64 %v, -4503599627370496\n";
        $out .= "  %ts = lshr i64 %v, 48\n";
        $out .= "  %nib = and i64 %ts, 15\n";
        $out .= "  %tag = select i1 %istag, i64 %nib, i64 6\n";
        $out .= "  switch i64 %tag, label %asint [\n";
        $out .= "    i64 2, label %asbool\n";
        $out .= "    i64 3, label %asnull\n";
        $out .= "    i64 4, label %asstr\n";
        $out .= "    i64 6, label %asfloat\n";
        $out .= "    i64 7, label %asarray\n";
        $out .= "  ]\n";
        $out .= "asint:\n";
        $out .= "  %i = call i64 @__manticore_unbox_int(i64 %v)\n";
        $out .= "  %id = sitofp i64 %i to double\n";
        $out .= "  ret double %id\n";
        $out .= "asbool:\n";
        $out .= "  %bb = and i64 %v, 1\n";
        $out .= "  %bd = sitofp i64 %bb to double\n";
        $out .= "  ret double %bd\n";
        $out .= "asnull:\n";
        $out .= "  ret double 0.0\n";
        $out .= "asstr:\n";
        $out .= "  %sp = and i64 %v, 281474976710655\n";
        $out .= "  %sptr = inttoptr i64 %sp to ptr\n";
        $out .= "  %sv = call double @strtod(ptr %sptr, ptr null)\n";
        $out .= "  ret double %sv\n";
        $out .= "asfloat:\n";
        $out .= "  %fd = bitcast i64 %v to double\n";
        $out .= "  ret double %fd\n";
        $out .= "asarray:\n";
        $out .= "  %ap = and i64 %v, 281474976710655\n";
        $out .= "  %aptr = inttoptr i64 %ap to ptr\n";
        $out .= "  %alen = load i64, ptr %aptr\n";
        $out .= "  %ane = icmp ne i64 %alen, 0\n";
        $out .= "  %az = uitofp i1 %ane to double\n";
        $out .= "  ret double %az\n";
        $out .= "}\n";
        return $out;
    }

    /**
     * `__manticore_tagged_compare(a, b) -> i64` (-1 / 0 / +1) — a runtime,
     * tag-dispatched ordering of two NaN-boxed cells (PHP-ish `<=>` semantics for
     * the common homogeneous cases). Both string → strcmp; both int → signed int
     * compare (no double-precision loss on large ints); otherwise numeric compare
     * via the double promotion. Used when both operands of an ordering compare are
     * statically CELL (guaranteed boxed) — e.g. sorting `array_keys` output or an
     * erased mixed array, where a raw int compare would order string keys by
     * pointer. Callers do `icmp <pred> result, 0`.
     */
    private function taggedCompareRuntime(): string
    {
        $out  = "\ndefine i64 @__manticore_tagged_compare(i64 %a, i64 %b) {\n";
        $out .= "entry:\n";
        $out .= "  %ta = call i64 @__manticore_tag(i64 %a)\n";
        $out .= "  %tb = call i64 @__manticore_tag(i64 %b)\n";
        $out .= "  %as = icmp eq i64 %ta, 4\n";
        $out .= "  %bs = icmp eq i64 %tb, 4\n";
        $out .= "  %bothstr = and i1 %as, %bs\n";
        $out .= "  br i1 %bothstr, label %scmp, label %chkint\n";
        $out .= "scmp:\n";
        $out .= "  %pa = and i64 %a, 281474976710655\n";
        $out .= "  %ppa = inttoptr i64 %pa to ptr\n";
        $out .= "  %pb = and i64 %b, 281474976710655\n";
        $out .= "  %ppb = inttoptr i64 %pb to ptr\n";
        $out .= "  %sc = call i64 @__mir_str_cmp(ptr %ppa, ptr %ppb)\n";
        $out .= "  ret i64 %sc\n";
        $out .= "chkint:\n";
        $out .= "  %ai = icmp eq i64 %ta, 1\n";
        $out .= "  %bi = icmp eq i64 %tb, 1\n";
        $out .= "  %bothint = and i1 %ai, %bi\n";
        $out .= "  br i1 %bothint, label %icmp, label %fcmp\n";
        $out .= "icmp:\n";
        $out .= "  %ua = call i64 @__manticore_unbox_int(i64 %a)\n";
        $out .= "  %ub = call i64 @__manticore_unbox_int(i64 %b)\n";
        $out .= "  %ilt = icmp slt i64 %ua, %ub\n";
        $out .= "  %igt = icmp sgt i64 %ua, %ub\n";
        $out .= "  %isel = select i1 %igt, i64 1, i64 0\n";
        $out .= "  %ires = select i1 %ilt, i64 -1, i64 %isel\n";
        $out .= "  ret i64 %ires\n";
        $out .= "fcmp:\n";
        $out .= "  %da = call double @__manticore_tagged_to_double(i64 %a)\n";
        $out .= "  %db = call double @__manticore_tagged_to_double(i64 %b)\n";
        $out .= "  %flt = fcmp olt double %da, %db\n";
        $out .= "  %fgt = fcmp ogt double %da, %db\n";
        $out .= "  %fsel = select i1 %fgt, i64 1, i64 0\n";
        $out .= "  %fres = select i1 %flt, i64 -1, i64 %fsel\n";
        $out .= "  ret i64 %fres\n";
        $out .= "}\n";
        return $out;
    }

    /**
     * `==` / `===` for two NaN-boxed cells with PHP juggling. `__manticore_tagged_
     * loose_eq`: numbers, bools, null and NUMERIC strings compare numerically
     * (`5 == "5"`, `"10" == "1e1"`, `null == 0`); two strings where at least one is
     * non-numeric compare byte-wise; anything else falls back to raw-bit identity.
     * `__manticore_tagged_strict_eq`: different tag ⇒ not equal; strings compare
     * byte-wise (non-interned), everything else by raw bits. `__mir_is_numeric_str`
     * is the PHP-numeric-string test (strtod consumed the whole string modulo
     * trailing ASCII whitespace). Used by the cell==cell / cell===cell path.
     */
    private function taggedEqRuntime(): string
    {
        $this->needsStrcmp = true;
        $this->libcExtra['strtod'] = 'declare double @strtod(ptr, ptr)';
        // __mir_is_numeric_str(s) -> i1
        $out  = "\ndefine i1 @__mir_is_numeric_str(ptr %s) {\nentry:\n";
        $out .= "  %c0 = load i8, ptr %s\n";
        $out .= "  %empty = icmp eq i8 %c0, 0\n";
        $out .= "  br i1 %empty, label %no, label %parse\n";
        $out .= "parse:\n";
        $out .= "  %end = alloca ptr\n";
        $out .= "  %d = call double @strtod(ptr %s, ptr %end)\n";
        $out .= "  %ep = load ptr, ptr %end\n";
        $out .= "  %noparse = icmp eq ptr %ep, %s\n";
        $out .= "  br i1 %noparse, label %no, label %tail\n";
        $out .= "tail:\n";
        $out .= "  %cur = phi ptr [ %ep, %parse ], [ %nxt, %skip ]\n";
        $out .= "  %tc = load i8, ptr %cur\n";
        $out .= "  %isnul = icmp eq i8 %tc, 0\n";
        $out .= "  br i1 %isnul, label %yes, label %chkws\n";
        $out .= "chkws:\n";
        // ASCII whitespace: space(32) tab(9) nl(10) cr(13) vt(11) ff(12)
        $out .= "  %w1 = icmp eq i8 %tc, 32\n  %w2 = icmp eq i8 %tc, 9\n";
        $out .= "  %w3 = icmp eq i8 %tc, 10\n  %w4 = icmp eq i8 %tc, 13\n";
        $out .= "  %w5 = icmp eq i8 %tc, 11\n  %w6 = icmp eq i8 %tc, 12\n";
        $out .= "  %o1 = or i1 %w1, %w2\n  %o2 = or i1 %w3, %w4\n  %o3 = or i1 %w5, %w6\n";
        $out .= "  %o4 = or i1 %o1, %o2\n  %isws = or i1 %o4, %o3\n";
        $out .= "  br i1 %isws, label %skip, label %no\n";
        $out .= "skip:\n  %nxt = getelementptr inbounds i8, ptr %cur, i64 1\n  br label %tail\n";
        $out .= "yes:\n  ret i1 true\n";
        $out .= "no:\n  ret i1 false\n}\n";

        // isNumericCell(v) -> i1 : tag int/bool/null/float, OR a numeric string.
        $out .= "define i1 @__mir_cell_numeric(i64 %v) {\nentry:\n";
        $out .= "  %t = call i64 @__manticore_tag(i64 %v)\n";
        $out .= "  %t1 = icmp eq i64 %t, 1\n  %t2 = icmp eq i64 %t, 2\n";
        $out .= "  %t3 = icmp eq i64 %t, 3\n  %t6 = icmp eq i64 %t, 6\n";
        $out .= "  %n1 = or i1 %t1, %t2\n  %n2 = or i1 %t3, %t6\n  %numty = or i1 %n1, %n2\n";
        $out .= "  br i1 %numty, label %y, label %chkstr\n";
        $out .= "chkstr:\n";
        $out .= "  %isstr = icmp eq i64 %t, 4\n";
        $out .= "  br i1 %isstr, label %s, label %n\n";
        $out .= "s:\n  %p = and i64 %v, 281474976710655\n  %pp = inttoptr i64 %p to ptr\n";
        $out .= "  %sn = call i1 @__mir_is_numeric_str(ptr %pp)\n  ret i1 %sn\n";
        $out .= "y:\n  ret i1 true\n  n:\n  ret i1 false\n}\n";

        // __manticore_tagged_loose_eq(a,b) -> i64 (0/1)
        $out .= "define i64 @__manticore_tagged_loose_eq(i64 %a, i64 %b) {\nentry:\n";
        $out .= "  %an = call i1 @__mir_cell_numeric(i64 %a)\n";
        $out .= "  %bn = call i1 @__mir_cell_numeric(i64 %b)\n";
        $out .= "  %bothnum = and i1 %an, %bn\n";
        $out .= "  br i1 %bothnum, label %num, label %chkstr\n";
        $out .= "num:\n";
        $out .= "  %da = call double @__manticore_tagged_to_double(i64 %a)\n";
        $out .= "  %db = call double @__manticore_tagged_to_double(i64 %b)\n";
        $out .= "  %feq = fcmp oeq double %da, %db\n";
        $out .= "  %fz = zext i1 %feq to i64\n  ret i64 %fz\n";
        $out .= "chkstr:\n";
        $out .= "  %ta = call i64 @__manticore_tag(i64 %a)\n  %tb = call i64 @__manticore_tag(i64 %b)\n";
        $out .= "  %sa = icmp eq i64 %ta, 4\n  %sb = icmp eq i64 %tb, 4\n";
        $out .= "  %bothstr = and i1 %sa, %sb\n";
        $out .= "  br i1 %bothstr, label %scmp, label %raw\n";
        $out .= "scmp:\n";
        $out .= "  %pa = and i64 %a, 281474976710655\n  %ppa = inttoptr i64 %pa to ptr\n";
        $out .= "  %pb = and i64 %b, 281474976710655\n  %ppb = inttoptr i64 %pb to ptr\n";
        $out .= "  %se = call i1 @__mir_str_eq(ptr %ppa, ptr %ppb)\n  %sz = zext i1 %se to i64\n  ret i64 %sz\n";
        $out .= "raw:\n";
        $out .= "  %req = icmp eq i64 %a, %b\n  %rz = zext i1 %req to i64\n  ret i64 %rz\n}\n";

        // __manticore_tagged_strict_eq(a,b) -> i64 (0/1)
        $out .= "define i64 @__manticore_tagged_strict_eq(i64 %a, i64 %b) {\nentry:\n";
        $out .= "  %ta = call i64 @__manticore_tag(i64 %a)\n  %tb = call i64 @__manticore_tag(i64 %b)\n";
        $out .= "  %same = icmp eq i64 %ta, %tb\n";
        $out .= "  br i1 %same, label %chk, label %ne\n";
        $out .= "chk:\n";
        $out .= "  %isstr = icmp eq i64 %ta, 4\n";
        $out .= "  br i1 %isstr, label %scmp, label %raw\n";
        $out .= "scmp:\n";
        $out .= "  %pa = and i64 %a, 281474976710655\n  %ppa = inttoptr i64 %pa to ptr\n";
        $out .= "  %pb = and i64 %b, 281474976710655\n  %ppb = inttoptr i64 %pb to ptr\n";
        $out .= "  %se = call i1 @__mir_str_eq(ptr %ppa, ptr %ppb)\n  %sz = zext i1 %se to i64\n  ret i64 %sz\n";
        $out .= "raw:\n";
        $out .= "  %req = icmp eq i64 %a, %b\n  %rz = zext i1 %req to i64\n  ret i64 %rz\n";
        $out .= "ne:\n  ret i64 0\n}\n";
        return $out;
    }

    /**
     * Dynamic `cellA <op> cellB` for `+ - *`: PHP promotes to float iff either
     * operand is a float, else integer. Each helper checks the two tags, and on
     * a float tag (6) on either side does the float op over tagged_to_double and
     * re-boxes a float cell, otherwise the integer op over tagged_to_int and
     * re-boxes an int cell. Operands are always boxed cells (emitTaggedArith).
     */
    private function taggedArithRuntime(): string
    {
        return $this->taggedArithOne('add', 'add', 'fadd')
            . $this->taggedArithOne('sub', 'sub', 'fsub')
            . $this->taggedArithOne('mul', 'mul', 'fmul');
    }

    private function taggedArithOne(string $name, string $iop, string $fop): string
    {
        $out  = "\ndefine i64 @__manticore_tagged_" . $name . "(i64 %a, i64 %b) {\n";
        $out .= "entry:\n";
        $out .= "  %aistag = icmp ugt i64 %a, -4503599627370496\n";
        $out .= "  %tas = lshr i64 %a, 48\n";
        $out .= "  %tan = and i64 %tas, 15\n";
        $out .= "  %taa = select i1 %aistag, i64 %tan, i64 6\n";
        $out .= "  %bistag = icmp ugt i64 %b, -4503599627370496\n";
        $out .= "  %tbs = lshr i64 %b, 48\n";
        $out .= "  %tbn = and i64 %tbs, 15\n";
        $out .= "  %tbb = select i1 %bistag, i64 %tbn, i64 6\n";
        $out .= "  %afl = icmp eq i64 %taa, 6\n";
        $out .= "  %bfl = icmp eq i64 %tbb, 6\n";
        $out .= "  %isf = or i1 %afl, %bfl\n";
        $out .= "  br i1 %isf, label %flt, label %int\n";
        $out .= "flt:\n";
        $out .= "  %fa = call double @__manticore_tagged_to_double(i64 %a)\n";
        $out .= "  %fb = call double @__manticore_tagged_to_double(i64 %b)\n";
        $out .= "  %fr = " . $fop . " double %fa, %fb\n";
        $out .= "  %fboxed = call i64 @__manticore_box_float(double %fr)\n";
        $out .= "  ret i64 %fboxed\n";
        $out .= "int:\n";
        $out .= "  %ia = call i64 @__manticore_tagged_to_int(i64 %a)\n";
        $out .= "  %ib = call i64 @__manticore_tagged_to_int(i64 %b)\n";
        $out .= "  %ir = " . $iop . " i64 %ia, %ib\n";
        $out .= "  %iboxed = call i64 @__manticore_box_int(i64 %ir)\n";
        $out .= "  ret i64 %iboxed\n";
        $out .= "}\n";
        return $out;
    }

    /**
     * Truthiness of a NaN-boxed cell → i64 0/1 (PHP semantics): int≠0, bool bit,
     * null→0, string truthy unless "" or "0", float≠0.0, array non-empty, object
     * always true. A raw cell can't be tested with `icmp ne i64 v, 0` — a boxed
     * `0`/`false`/`""` has non-zero tag bits and would read truthy.
     */
    private function taggedTruthyRuntime(): string
    {
        $out  = "\ndefine i64 @__manticore_tagged_truthy(i64 %v) {\n";
        $out .= "entry:\n";
        $out .= "  %istag = icmp ugt i64 %v, -4503599627370496\n";
        $out .= "  %ts = lshr i64 %v, 48\n";
        $out .= "  %nib = and i64 %ts, 15\n";
        $out .= "  %tag = select i1 %istag, i64 %nib, i64 6\n";
        $out .= "  switch i64 %tag, label %asint [\n";
        $out .= "    i64 2, label %asbool\n";
        $out .= "    i64 3, label %asnull\n";
        $out .= "    i64 4, label %asstr\n";
        $out .= "    i64 6, label %asfloat\n";
        $out .= "    i64 7, label %asarray\n";
        $out .= "    i64 8, label %astrue\n";
        $out .= "  ]\n";
        $out .= "asint:\n";
        $out .= "  %i = call i64 @__manticore_unbox_int(i64 %v)\n";
        $out .= "  %inz = icmp ne i64 %i, 0\n";
        $out .= "  %ir = zext i1 %inz to i64\n";
        $out .= "  ret i64 %ir\n";
        $out .= "asbool:\n";
        $out .= "  %bb = and i64 %v, 1\n";
        $out .= "  ret i64 %bb\n";
        $out .= "asnull:\n";
        $out .= "  ret i64 0\n";
        $out .= "asfloat:\n";
        $out .= "  %fd = bitcast i64 %v to double\n";
        $out .= "  %fnz = fcmp une double %fd, 0.000000e+00\n";
        $out .= "  %fr = zext i1 %fnz to i64\n";
        $out .= "  ret i64 %fr\n";
        $out .= "asarray:\n";
        $out .= "  %ap = and i64 %v, 281474976710655\n";
        $out .= "  %anull = icmp eq i64 %ap, 0\n";
        $out .= "  br i1 %anull, label %sfalse, label %aload\n";
        $out .= "aload:\n";
        $out .= "  %aptr = inttoptr i64 %ap to ptr\n";
        $out .= "  %alen = load i64, ptr %aptr\n";
        $out .= "  %ane = icmp ne i64 %alen, 0\n";
        $out .= "  %ar = zext i1 %ane to i64\n";
        $out .= "  ret i64 %ar\n";
        $out .= "astrue:\n";
        $out .= "  ret i64 1\n";
        // string: falsy iff "" (byte0==0) or "0" (byte0=='0' && byte1==0).
        $out .= "asstr:\n";
        $out .= "  %sp = and i64 %v, 281474976710655\n";
        $out .= "  %snull = icmp eq i64 %sp, 0\n";
        $out .= "  br i1 %snull, label %sfalse, label %sload\n";
        $out .= "sload:\n";
        $out .= "  %sptr = inttoptr i64 %sp to ptr\n";
        $out .= "  %c0 = load i8, ptr %sptr\n";
        $out .= "  %empty = icmp eq i8 %c0, 0\n";
        $out .= "  br i1 %empty, label %sfalse, label %schk0\n";
        $out .= "schk0:\n";
        $out .= "  %isz = icmp eq i8 %c0, 48\n";
        $out .= "  br i1 %isz, label %schkb1, label %strue\n";
        $out .= "schkb1:\n";
        $out .= "  %p1 = getelementptr inbounds i8, ptr %sptr, i64 1\n";
        $out .= "  %c1 = load i8, ptr %p1\n";
        $out .= "  %c1z = icmp eq i8 %c1, 0\n";
        $out .= "  br i1 %c1z, label %sfalse, label %strue\n";
        $out .= "strue:\n  ret i64 1\n";
        $out .= "sfalse:\n  ret i64 0\n";
        $out .= "}\n";
        return $out;
    }

    private function intToStrRuntime(): string
    {
        $out = $this->intToStrImpl('@__mir_int_to_str', '@__mir_str_alloc');
        if ($this->needsArena) {
            $out .= $this->intToStrImpl('@__mir_int_to_str_arena', '@__mir_str_alloc_arena');
        }
        $out .= $this->intFmtRuntime();
        return $out;
    }

    /**
     * `__mir_int_len(v) -> i64` decimal char count (digits + '-'), and
     * `__mir_int_fmt(base, off, v)` writing that decimal at `base+off` (no NUL).
     * Lets a concat with an int operand format it straight into the fused
     * buffer — no `__mir_int_to_str` temp string, memcpy, or release. Same
     * unsigned-magnitude digit loop as {@see intToStrImpl} (INT_MIN-safe).
     */
    private function intFmtRuntime(): string
    {
        $out  = "\ndefine i64 @__mir_int_len(i64 %v) {\n";
        $out .= "entry:\n";
        $out .= "  %isz = icmp eq i64 %v, 0\n";
        $out .= "  br i1 %isz, label %zero, label %nz\n";
        $out .= "zero:\n  ret i64 1\n";
        $out .= "nz:\n";
        $out .= "  %neg = icmp slt i64 %v, 0\n";
        $out .= "  %nvneg = sub i64 0, %v\n";
        $out .= "  %av = select i1 %neg, i64 %nvneg, i64 %v\n";
        $out .= "  br label %cnt\n";
        $out .= "cnt:\n";
        $out .= "  %ct = phi i64 [ %av, %nz ], [ %cq, %cnt ]\n";
        $out .= "  %cn = phi i64 [ 0, %nz ], [ %cn1, %cnt ]\n";
        $out .= "  %cq = udiv i64 %ct, 10\n";
        $out .= "  %cn1 = add i64 %cn, 1\n";
        $out .= "  %cmore = icmp ne i64 %cq, 0\n";
        $out .= "  br i1 %cmore, label %cnt, label %done\n";
        $out .= "done:\n";
        $out .= "  %signb = zext i1 %neg to i64\n";
        $out .= "  %total = add i64 %cn1, %signb\n";
        $out .= "  ret i64 %total\n";
        $out .= "}\n";

        $out .= "\ndefine void @__mir_int_fmt(ptr %base, i64 %off, i64 %v) {\n";
        $out .= "entry:\n";
        $out .= "  %buf = getelementptr inbounds i8, ptr %base, i64 %off\n";
        $out .= "  %isz = icmp eq i64 %v, 0\n";
        $out .= "  br i1 %isz, label %zero, label %nz\n";
        $out .= "zero:\n  store i8 48, ptr %buf\n  ret void\n";
        $out .= "nz:\n";
        $out .= "  %neg = icmp slt i64 %v, 0\n";
        $out .= "  %nvneg = sub i64 0, %v\n";
        $out .= "  %av = select i1 %neg, i64 %nvneg, i64 %v\n";
        $out .= "  br label %cnt\n";
        $out .= "cnt:\n";
        $out .= "  %ct = phi i64 [ %av, %nz ], [ %cq, %cnt ]\n";
        $out .= "  %cn = phi i64 [ 0, %nz ], [ %cn1, %cnt ]\n";
        $out .= "  %cq = udiv i64 %ct, 10\n";
        $out .= "  %cn1 = add i64 %cn, 1\n";
        $out .= "  %cmore = icmp ne i64 %cq, 0\n";
        $out .= "  br i1 %cmore, label %cnt, label %cntdone\n";
        $out .= "cntdone:\n";
        $out .= "  %signb = zext i1 %neg to i64\n";
        $out .= "  %total = add i64 %cn1, %signb\n";
        $out .= "  %mb = select i1 %neg, i8 45, i8 0\n";
        $out .= "  store i8 %mb, ptr %buf\n";
        $out .= "  %lastpos = sub i64 %total, 1\n";
        $out .= "  br label %wr\n";
        $out .= "wr:\n";
        $out .= "  %wt = phi i64 [ %av, %cntdone ], [ %wq, %wr ]\n";
        $out .= "  %wp = phi i64 [ %lastpos, %cntdone ], [ %wp1, %wr ]\n";
        $out .= "  %wq = udiv i64 %wt, 10\n";
        $out .= "  %wr10 = urem i64 %wt, 10\n";
        $out .= "  %wch = add i64 %wr10, 48\n";
        $out .= "  %wch8 = trunc i64 %wch to i8\n";
        $out .= "  %wdst = getelementptr inbounds i8, ptr %buf, i64 %wp\n";
        $out .= "  store i8 %wch8, ptr %wdst\n";
        $out .= "  %wp1 = sub i64 %wp, 1\n";
        $out .= "  %wmore = icmp ne i64 %wq, 0\n";
        $out .= "  br i1 %wmore, label %wr, label %wrdone\n";
        $out .= "wrdone:\n  ret void\n";
        $out .= "}\n";
        return $out;
    }

    private function intToStrImpl(string $name, string $alloc): string
    {
        // Hand-rolled decimal: a digit loop (udiv/urem by 10), NOT snprintf —
        // the format-string parse dominated int→string, which is on the concat /
        // array-key hot paths (millions of calls). Magnitude via unsigned negate
        // so INT_MIN is safe (0 - INT_MIN wraps to 2^63, divides correctly).
        $out  = "\ndefine ptr " . $name . "(i64 %v) {\n";
        $out .= "entry:\n";
        $out .= "  %buf = call ptr " . $alloc . "(i64 24)\n";
        $out .= "  %isz = icmp eq i64 %v, 0\n";
        $out .= "  br i1 %isz, label %zero, label %nz\n";
        $out .= "zero:\n";
        $out .= "  store i8 48, ptr %buf\n";              // '0'
        $out .= "  %z1 = getelementptr inbounds i8, ptr %buf, i64 1\n";
        $out .= "  store i8 0, ptr %z1\n";
        $out .= "  call void @__mir_str_set_len(ptr %buf, i64 1)\n";
        $out .= "  ret ptr %buf\n";
        $out .= "nz:\n";
        $out .= "  %neg = icmp slt i64 %v, 0\n";
        $out .= "  %nvneg = sub i64 0, %v\n";
        $out .= "  %av = select i1 %neg, i64 %nvneg, i64 %v\n"; // unsigned magnitude
        $out .= "  br label %cnt\n";
        // count digits
        $out .= "cnt:\n";
        $out .= "  %ct = phi i64 [ %av, %nz ], [ %cq, %cnt ]\n";
        $out .= "  %cn = phi i64 [ 0, %nz ], [ %cn1, %cnt ]\n";
        $out .= "  %cq = udiv i64 %ct, 10\n";
        $out .= "  %cn1 = add i64 %cn, 1\n";
        $out .= "  %cmore = icmp ne i64 %cq, 0\n";
        $out .= "  br i1 %cmore, label %cnt, label %cntdone\n";
        $out .= "cntdone:\n";
        $out .= "  %signb = zext i1 %neg to i64\n";
        $out .= "  %total = add i64 %cn1, %signb\n";       // total chars (digits + sign)
        $out .= "  %dst0 = getelementptr inbounds i8, ptr %buf, i64 0\n";
        $out .= "  %mb = select i1 %neg, i8 45, i8 0\n";   // '-' or no-op
        $out .= "  store i8 %mb, ptr %dst0\n";             // sign goes at buf[0] (overwritten if !neg below)
        $out .= "  %lastpos = sub i64 %total, 1\n";
        $out .= "  br label %wr\n";
        // write digits backward from buf[total-1] down to buf[signb]
        $out .= "wr:\n";
        $out .= "  %wt = phi i64 [ %av, %cntdone ], [ %wq, %wr ]\n";
        $out .= "  %wp = phi i64 [ %lastpos, %cntdone ], [ %wp1, %wr ]\n";
        $out .= "  %wq = udiv i64 %wt, 10\n";
        $out .= "  %wr10 = urem i64 %wt, 10\n";
        $out .= "  %wch = add i64 %wr10, 48\n";
        $out .= "  %wch8 = trunc i64 %wch to i8\n";
        $out .= "  %wdst = getelementptr inbounds i8, ptr %buf, i64 %wp\n";
        $out .= "  store i8 %wch8, ptr %wdst\n";
        $out .= "  %wp1 = sub i64 %wp, 1\n";
        $out .= "  %wmore = icmp ne i64 %wq, 0\n";
        $out .= "  br i1 %wmore, label %wr, label %wrdone\n";
        $out .= "wrdone:\n";
        $out .= "  %nulp = getelementptr inbounds i8, ptr %buf, i64 %total\n";
        $out .= "  store i8 0, ptr %nulp\n";
        $out .= "  call void @__mir_str_set_len(ptr %buf, i64 %total)\n";
        $out .= "  ret ptr %buf\n";
        $out .= "}\n";
        return $out;
    }

    private function floatToStrImpl(string $name, string $alloc): string
    {
        $out  = "\ndefine ptr " . $name . "(double %v) {\n";
        $out .= "entry:\n";
        $out .= "  %buf = call ptr " . $alloc . "(i64 32)\n";
        $out .= "  %n = call i32 (ptr, i64, ptr, ...) @snprintf(ptr %buf, i64 32, ptr @.fmt.pg, double %v)\n";
        $out .= "  %nl = sext i32 %n to i64\n";
        $out .= "  call void @__mir_str_set_len(ptr %buf, i64 %nl)\n";
        $out .= "  ret ptr %buf\n";
        $out .= "}\n";
        return $out;
    }

    /**
     * `__mir_float_shortest(double) -> ptr` — the SHORTEST decimal that
     * round-trips back to the same double (PHP's `serialize_precision = -1`,
     * used by var_dump / json / var_export). Probe `%.Ng` for N = 1..17 and
     * return the first whose strtod re-parses exactly. (No PHP E-notation
     * normalization yet — a follow-up; the value is exact.)
     */
    private function floatShortestImpl(): string
    {
        // snprintf/strtod declares are set in biVarDump (body emission) so they
        // precede the header declare block — too late if set here.
        // PHP renders non-finite floats UPPERCASE ("INF"/"-INF"/"NAN"), unlike
        // C's snprintf ("inf"/"nan"); return those literals directly (and a NaN
        // never satisfies the strtod round-trip below — `NaN != NaN` — so it
        // must be caught here regardless).
        $out  = $this->strGlobalDef('@.f.inf', 'INF');
        $out .= $this->strGlobalDef('@.f.ninf', '-INF');
        $out .= $this->strGlobalDef('@.f.nan', 'NAN');
        $out .= "\ndefine ptr @__mir_float_shortest(double %v) {\n";
        $out .= "entry:\n";
        $out .= "  %buf = call ptr @__mir_str_alloc(i64 40)\n";
        $out .= "  %pp = alloca i32\n  store i32 1, ptr %pp\n";
        // An integral float in i64 range prints as a plain integer (`%.0f` →
        // "100"), matching PHP — the shortest `%g` would render a round number
        // in scientific notation ("1e+02"). fptosi round-trip tests integrality.
        $out .= "  %neg = fneg double %v\n";
        $out .= "  %isneg = fcmp olt double %v, 0.000000e+00\n";
        $out .= "  %absv = select i1 %isneg, double %neg, double %v\n";
        $out .= "  %isnan = fcmp uno double %v, %v\n";
        $out .= "  br i1 %isnan, label %retnan, label %ckinf\n";
        $out .= "ckinf:\n";
        $out .= "  %isinf = fcmp oeq double %absv, 0x7FF0000000000000\n";
        $out .= "  br i1 %isinf, label %retinf, label %finite\n";
        $out .= "retnan:\n  ret ptr " . $this->strSymBytes('@.f.nan') . "\n";
        $out .= "retinf:\n";
        $out .= "  %infsel = select i1 %isneg, ptr " . $this->strSymBytes('@.f.ninf')
              . ", ptr " . $this->strSymBytes('@.f.inf') . "\n";
        $out .= "  ret ptr %infsel\n";
        $out .= "finite:\n";
        $out .= "  %insafe = fcmp olt double %absv, 1.000000e+15\n";
        $out .= "  br i1 %insafe, label %chkint, label %loop\n";
        $out .= "chkint:\n";
        $out .= "  %iv = fptosi double %v to i64\n";
        $out .= "  %bk = sitofp i64 %iv to double\n";
        $out .= "  %isint = fcmp oeq double %bk, %v\n";
        $out .= "  br i1 %isint, label %asint, label %loop\n";
        $out .= "asint:\n";
        $out .= "  %ni = call i32 (ptr, i64, ptr, ...) @snprintf(ptr %buf, i64 40, ptr @.fmt.f0, double %v)\n";
        $out .= "  %nil = sext i32 %ni to i64\n";
        $out .= "  call void @__mir_str_set_len(ptr %buf, i64 %nil)\n";
        $out .= "  ret ptr %buf\n";
        $out .= "loop:\n  %p = load i32, ptr %pp\n  %over = icmp sgt i32 %p, 17\n";
        $out .= "  br i1 %over, label %done, label %try\n";
        $out .= "try:\n";
        $out .= "  call i32 (ptr, i64, ptr, ...) @snprintf(ptr %buf, i64 40, ptr @.fmt.starg, i32 %p, double %v)\n";
        $out .= "  %parsed = call double @strtod(ptr %buf, ptr null)\n";
        $out .= "  %eq = fcmp oeq double %parsed, %v\n";
        $out .= "  br i1 %eq, label %done, label %next\n";
        $out .= "next:\n  %p1 = add i32 %p, 1\n  store i32 %p1, ptr %pp\n  br label %loop\n";
        $out .= "done:\n";
        $out .= "  %dl = call i64 @strlen(ptr %buf)\n";
        $out .= "  call void @__mir_str_set_len(ptr %buf, i64 %dl)\n";
        $out .= "  ret ptr %buf\n}\n";
        return $out;
    }

    /**
     * Single allocation gateway (contract step #5). Every MIR value
     * allocation routes through `@__mir_alloc` (heap) or, for arena-kind
     * values, `@__mir_arena_alloc` — so the malloc-vs-arena-vs-rc choice
     * lives in ONE place, not scattered inline. EmitLlvm picks the
     * symbol from the node's {@see \Compile\Mir\AllocationKind}; the
     * strategy bodies live here.
     *
     * Arena = region with a LIFO scope stack. `arena_alloc` mallocs and
     * records the pointer; `arena_enter` pushes the current count;
     * `arena_leave` frees everything allocated since the matching enter.
     * Only confined (non-escaping) values are routed here, so the
     * scope-exit free is always safe. Emitted only when `needsArena`.
     */

    /**
     * Fold a PHP name into an LLVM-safe symbol fragment. Namespace
     * separators (`\`) are illegal in unquoted LLVM identifiers, so they
     * collapse to `_` — applied consistently at the definition and every
     * call / global site so a namespaced class or function still links.
     */
    private function mangle(string $name): string
    {
        $out = '';
        $n = \strlen($name);
        for ($i = 0; $i < $n; $i = $i + 1) {
            $c = \substr($name, $i, 1);
            if ($c === '\\') { $out .= '_'; } else { $out .= $c; }
        }
        return $out;
    }

    private function emitFunction(FunctionDef $fn): string
    {
        // Signature-only stdlib import: emit a bare `declare`, no body. The
        // definition is linked from the prebuilt stdlib.o. Uniform i64 ABI →
        // arity-many i64 params (variadic packs into one vec arg → one param).
        if ($fn->isExtern) {
            $params = '';
            $first = true;
            foreach ($fn->params as $ignored) {
                if (!$first) { $params .= ', '; }
                $first = false;
                $params .= 'i64';
            }
            return 'declare i64 @manticore_' . $this->mangle($fn->name)
                . '(' . $params . ")\n";
        }
        $this->nextId = 0;
        $this->nextLabel = 0;
        $this->userLabels = [];
        $this->currentFnName = $fn->name;
        $this->currentFnBody = $fn->body;
        $this->currentFnHasArena = false;
        $this->vecAllocArena = false;
        $this->arenaVecLocals = [];
        $this->slots = [];
        $this->globalBackedLocals = [];
        $this->mutatedVecLocals = [];
        $this->collectMutatedVecs($fn->body);
        $this->collectStaticLocals($fn->body);
        // Top-level (`__main`) vars named in any `global $x` share the
        // same `@g_x` cell so writes are visible inside functions.
        if ($fn->name === '__main') {
            foreach ($this->globalVarNames as $gname) {
                $this->globalBackedLocals[$gname] = '@g_' . $gname;
            }
        }
        $this->breakLabel = '';
        $this->continueLabel = '';
        $this->breakStack = [];
        $this->continueStack = [];
        // By-ref params: the slot holds the caller's variable address;
        // loads/stores deref it.
        $this->refLocals = [];
        foreach ($fn->params as $p) {
            if ($p->byRef) { $this->refLocals[$p->name] = true; }
        }
        $this->currentReturnsByRef = $fn->returnsByRef;
        $this->currentReturnType = $fn->returnType;
        $this->currentFnIsClosure = false;
        $this->finallyStack = [];

        $isMain = $fn->name === '__main';
        if ($isMain) {
            // Library build (stdlib.o): no entry point — the program supplies
            // its own `@main`. The bundled stdlib has no top-level code, so
            // dropping `__main` loses nothing.
            if ($this->emitLibrary) { return ''; }
            return $this->emitMain($fn);
        }
        if ($fn->ffiSymbol !== null) {
            return $this->emitFfiWrapper($fn);
        }
        if ($fn->isGenerator) {
            return $this->emitGenerator($fn);
        }

        // A closure fn takes an env ptr (the closure struct) followed by
        // its declared params; its first `capCnt` "params" are captures
        // unpacked from env slot 1+ rather than passed by the caller.
        $capCnt = $this->closureCaptures[$fn->name] ?? -1;
        $isClosure = $capCnt >= 0;
        $this->currentFnIsClosure = $isClosure;
        $body = '';
        // The built-in Throwable/Exception/Error hierarchy is identical
        // boilerplate in every module, so emit it `linkonce_odr` — that lets
        // a user object link against the prebuilt stdlib.o (which also carries
        // the prelude) without duplicate-symbol errors, and lets the linker
        // drop it when a program references no exceptions. No-op for a lone .o.
        $linkage = $fn->isPrelude ? 'linkonce_odr ' : '';
        if ($isClosure) {
            $paramSig = 'ptr %env';
            for ($pi = $capCnt; $pi < \count($fn->params); $pi = $pi + 1) {
                $paramSig .= ', i64 %arg.' . $fn->params[$pi]->name;
            }
            // Closures are dispatched through a function pointer stored in
            // their env struct, never referenced by external symbol across a
            // compilation unit — so `internal` linkage. Their names are
            // per-module counters (`__closure_N`); without internal linkage a
            // user object and the prebuilt stdlib.o (whose ctype arrow-fns are
            // also `__closure_N`) collide at link time.
            $header = 'define internal i64 @manticore_' . $this->mangle($fn->name) . '(' . $paramSig . ") {\nentry:\n";
            for ($pi = 0; $pi < $capCnt; $pi = $pi + 1) {
                $cn = $fn->params[$pi]->name;
                $slot = $this->allocSsa();
                $this->slots[$cn] = $slot;
                $body .= '  ' . $slot . " = alloca i64\n";
                $gep = $this->allocSsa();
                $body .= '  ' . $gep . ' = getelementptr inbounds i64, ptr %env, i64 ' . (string)($pi + 1) . "\n";
                $cv = $this->allocSsa();
                $body .= '  ' . $cv . ' = load i64, ptr ' . $gep . "\n";
                $body .= '  store i64 ' . $cv . ', ptr ' . $slot . "\n";
            }
            for ($pi = $capCnt; $pi < \count($fn->params); $pi = $pi + 1) {
                $pp = $fn->params[$pi];
                $cn = $pp->name;
                $slot = $this->allocSsa();
                $this->slots[$cn] = $slot;
                $body .= '  ' . $slot . " = alloca i64\n";
                // Uniform closure ABI: the caller passes every scalar arg as a
                // tagged cell. A param declared a concrete scalar unboxes the
                // cell back to its repr here (cell / array / obj params stay raw;
                // a real heap ptr masks to identity, a by-ref slot is an address).
                if (!$pp->byRef && $this->isCellScalarParam($pp->type)) {
                    $this->lastValue = '%arg.' . $cn;
                    $this->lastValueType = 'i64';
                    $body .= $this->unboxCellToType($pp->type);
                    $body .= $this->coerceToI64();
                    $body .= '  store i64 ' . $this->lastValue . ', ptr ' . $slot . "\n";
                } else {
                    $body .= '  store i64 %arg.' . $cn . ', ptr ' . $slot . "\n";
                }
            }
        } else {
            $paramSig = '';
            $first = true;
            foreach ($fn->params as $p) {
                if (!$first) { $paramSig .= ', '; }
                $first = false;
                $paramSig .= 'i64 %arg.' . $p->name;
            }
            $header = 'define ' . $linkage . 'i64 @manticore_' . $this->mangle($fn->name) . '(' . $paramSig . ") {\nentry:\n";
            foreach ($fn->params as $p) {
                $slot = $this->allocSsa();
                $this->slots[$p->name] = $slot;
                $body .= '  ' . $slot . " = alloca i64\n";
                $body .= '  store i64 %arg.' . $p->name . ', ptr ' . $slot . "\n";
                // PHP arrays are values: a by-VALUE array param the body mutates
                // in place (`$x[] = …` / `$x[$k] = …`) must not alias the caller's
                // buffer. Copy it on entry so the mutation is private. Restricted
                // to a SCALAR/STRING element (a nested array/obj element would be
                // shared by the flat copy → still leak/corrupt; that needs a
                // deep/rc-aware copy — left borrowed for now). By-ref params must
                // keep aliasing the caller, so they are excluded.
                if (!$p->byRef && $p->type->isArray()
                    && $this->isScalarElemArray($p->type)
                    && isset($this->mutatedVecLocals[$p->name])) {
                    $ld = $this->allocSsa();
                    $body .= '  ' . $ld . ' = load i64, ptr ' . $slot . "\n";
                    $lp = $this->allocSsa();
                    $body .= '  ' . $lp . ' = inttoptr i64 ' . $ld . " to ptr\n";
                    $cp = $this->allocSsa();
                    $body .= '  ' . $cp . ' = call ptr @__mir_array_copy(ptr ' . $lp . ")\n";
                    $ci = $this->allocSsa();
                    $body .= '  ' . $ci . ' = ptrtoint ptr ' . $cp . " to i64\n";
                    $body .= '  store i64 ' . $ci . ', ptr ' . $slot . "\n";
                }
            }
        }
        $paramNames = [];
        foreach ($fn->params as $p) { $paramNames[$p->name] = true; }
        $body .= $this->preallocateLocals($fn->body);
        $body .= $this->initRcObjSlots($fn->body, $paramNames);
        // Heap-box locals captured BY-REFERENCE by a closure: an escaping
        // closure keeps the box alive after this frame returns, so the box
        // can't be a stack slot. The box is a 1-word heap cell; the local
        // becomes a refLocal (its slot holds the box ptr → load/store deref),
        // and `use (&$x)` captures `load slot` (the box ptr) via the refLocal
        // branch in emitClosure. Params are excluded (a by-ref capture param
        // already holds an inherited box ptr). The box leaks (bounded — one
        // per by-ref-captured local per call), like the generator frame.
        $this->byRefCaptured = [];
        $this->collectByRefCaptured($fn->body);
        foreach ($this->byRefCaptured as $bname => $_) {
            if (isset($paramNames[$bname])) { continue; }
            if (!isset($this->slots[$bname])) { continue; }
            if (isset($this->refLocals[$bname])) { continue; }
            $box = $this->allocSsa();
            $body .= '  ' . $box . " = call ptr @__mir_alloc(i64 8)\n";
            $body .= '  store i64 0, ptr ' . $box . "\n";
            $bi = $this->allocSsa();
            $body .= '  ' . $bi . ' = ptrtoint ptr ' . $box . " to i64\n";
            $body .= '  store i64 ' . $bi . ', ptr ' . $this->slots[$bname] . "\n";
            $this->refLocals[$bname] = true;
        }
        // Stamp the correct backtrace frame name for a method now that the
        // callee identity is exact ($fn->name is stable — it drives the define
        // header). The caller pushed a bare method-name placeholder because a
        // stable receiver class isn't available at the call site under the
        // self-host. Overwrites this frame's name slot (index depth-1).
        if ($this->needsBacktrace && isset($this->methodDisplay[$fn->name])) {
            $body .= $this->btNameFix($this->methodDisplay[$fn->name]);
        }
        $body .= $this->emitNode($fn->body);
        $body .= "  ret i64 0\n";
        return $header . $body . "}\n\n";
    }

    /** Overwrite the top backtrace frame's name (index depth-1) with `$disp`,
     *  guarded on depth>0. Emitted at a method's entry so the frame carries
     *  the exact "Class->method" / "Class::method" the callee knows. */
    private function btNameFix(string $disp): string
    {
        $d = $this->allocSsa();
        $out = '  ' . $d . " = load i64, ptr @__mir_bt_depth\n";
        $c = $this->allocSsa();
        $out .= '  ' . $c . ' = icmp sgt i64 ' . $d . ", 0\n";
        $set = $this->allocLabel('btfix.set');
        $end = $this->allocLabel('btfix.end');
        $out .= '  br i1 ' . $c . ', label %' . $set . ', label %' . $end . "\n" . $set . ":\n";
        $i = $this->allocSsa();
        $out .= '  ' . $i . ' = sub i64 ' . $d . ", 1\n";
        $ep = $this->allocSsa();
        $out .= '  ' . $ep . ' = getelementptr inbounds [4096 x i64], ptr @__mir_bt_name, i64 0, i64 ' . $i . "\n";
        $sv = $this->allocSsa();
        $out .= '  ' . $sv . ' = ptrtoint ptr ' . $this->strLitId($this->internString($disp)) . " to i64\n";
        $out .= '  store i64 ' . $sv . ', ptr ' . $ep . "\n";
        $out .= '  br label %' . $end . "\n" . $end . ":\n";
        return $out;
    }

    /**
     * Collect locals captured by-reference by a closure in `$n` into
     * {@see $byRefCaptured} (the names the enclosing frame must heap-box).
     * Writes to instance state, NOT a by-ref param — a recursive `array
     * &$out` drops its writes through nested calls under self-host. Closure
     * captures are leaves; a nested closure's own captures are handled when
     * that fn is emitted.
     */
    /** True iff the tree contains a `->throw(...)` method call (Generator
     *  exception injection — gates the per-yield resume-point check). */
    private function scanGenThrow(Node $n): bool
    {
        if ($n->kind === Node::KIND_METHOD_CALL && $this->castMethodCall($n)->method === 'throw') {
            return true;
        }
        foreach (\Compile\Mir\Walk::children($n) as $c) {
            if ($this->scanGenThrow($c)) { return true; }
        }
        return false;
    }

    /** Prop names ever stored a value boxToCell can't box in place → keep RAW. */
    private array $cellPropNotBoxable = [];

    /** Prop names used as a RAW array base (`$this->p[...]`, `foreach ($this->p)`)
     *  — the SPL backing-slot pattern; never box (array-access reads the raw buffer). */
    private array $cellPropArrayBase = [];

    /** Prop names ever stored a concrete array value (needs a cell-array rebuild). */
    private array $cellPropHasArrayStore = [];

    /** Prop names ever stored a scalar/string/object (proof of a self-describing,
     *  heterogeneous slot — only then does an array store ride along as a boxed cell). */
    private array $cellPropHasInPlaceBox = [];

    /**
     * A value kind that NaN-boxes in place (a single box_* with no buffer
     * rebuild): scalars, string (box_ptr), object (box_object), and an
     * already-boxed cell. A concrete array/assoc would REBUILD (boxToCell
     * copies into a fresh cell-array — wrong for a co-owned / SPL backing slot)
     * and unknown/closure/generator mis-box, so those keep the slot RAW. A
     * boxed object cell var_dumps / `instanceof`s / dispatches correctly; a
     * chained `$cell->prop` still needs instanceof narrowing (see
     * inferPropertyAccess path-narrowing) — unguarded it hits the bag path.
     */
    private function cellBoxableKind(Type $t): bool
    {
        $k = $t->kind;
        return $k === Type::KIND_INT || $k === Type::KIND_FLOAT
            || $k === Type::KIND_BOOL || $k === Type::KIND_NULL
            || $k === Type::KIND_STRING || $k === Type::KIND_OBJ
            || $k === Type::KIND_CELL;
    }

    private function scanCellPropStores(Node $n): void
    {
        if ($n->kind === Node::KIND_STORE_PROPERTY) {
            $sp = $this->castStoreProperty($n);
            $vk = $sp->value->type->kind;
            if ($vk === Type::KIND_ARRAY) {
                // A concrete array can box (boxToCell rebuilds it as a cell-array),
                // but it only does so when the slot is already self-describing —
                // see cellPropBoxed. Tracked separately so an array-only prop keeps
                // its current raw behaviour (no regression for typed-array backing).
                $this->cellPropHasArrayStore[$sp->property] = true;
            } elseif (!$this->cellBoxableKind($sp->value->type)) {
                $this->cellPropNotBoxable[$sp->property] = true;
            } else {
                $this->cellPropHasInPlaceBox[$sp->property] = true;
            }
        }
        $base = $this->cellPropArrayBaseName($n);
        if ($base !== null) { $this->cellPropArrayBase[$base] = true; }
        foreach (\Compile\Mir\Walk::children($n) as $c) {
            $this->scanCellPropStores($c);
        }
    }

    /** The property name when $n uses `$obj->name` as a RAW array base
     *  (subscript read/write or foreach subject), else null. */
    private function cellPropArrayBaseName(Node $n): ?string
    {
        $base = null;
        if ($n->kind === Node::KIND_ARRAY_ACCESS) {
            $base = $this->castArrayAccess($n)->array;
        } elseif ($n->kind === Node::KIND_STORE_ELEMENT) {
            $base = $this->castStoreElement($n)->array;
        } elseif ($n->kind === Node::KIND_FOREACH) {
            $base = $this->castForeach($n)->array;
        }
        if ($base !== null && $base->kind === Node::KIND_PROPERTY_ACCESS) {
            return $this->castPropertyAccess($base)->property;
        }
        return null;
    }

    /**
     * A cell/`mixed` property that is self-describing (boxed NULL default +
     * box-store) rather than a raw cell-array backing slot. True iff the
     * declared type is a cell, the name is never used as a raw array base, and
     * every store boxes in place. A concrete array store rides along (boxed as a
     * cell-array) ONLY when the slot is also stored a scalar/string/object — i.e.
     * a genuinely heterogeneous bag; an array-only slot stays raw.
     */
    private function cellPropBoxed(?Type $ptype, string $prop): bool
    {
        if ($ptype === null || $ptype->kind !== Type::KIND_CELL) { return false; }
        if (isset($this->cellPropNotBoxable[$prop])) { return false; }
        if (isset($this->cellPropArrayBase[$prop])) { return false; }
        if (isset($this->cellPropHasArrayStore[$prop])
            && !isset($this->cellPropHasInPlaceBox[$prop])) {
            return false;
        }
        return true;
    }

    private function collectByRefCaptured(Node $n): void
    {
        if ($n->kind === Node::KIND_CLOSURE) {
            $cl = $this->castClosure($n);
            $i = 0;
            foreach ($cl->captures as $c) {
                if (($cl->captureByRef[$i] ?? false) && $c->kind === Node::KIND_LOAD_LOCAL) {
                    $this->byRefCaptured[$this->castLoadLocal($c)->name] = true;
                }
                $i = $i + 1;
            }
            return;
        }
        foreach (\Compile\Mir\Walk::children($n) as $c) {
            $this->collectByRefCaptured($c);
        }
    }

    // Generator frame layout:
    //   resume_fn@0, state@8, current@16, key@24, nextkey@32,
    //   sent@40, retval@48, locals@56+
    // state: 0 = not started, k = suspended at yield k, -1 = finished.
    private const GEN_HEADER = 56;

    /**
     * A generator lowers to TWO functions: a creator `@manticore_<name>`
     * that heap-allocates the frame (storing params + the resume fn ptr) and
     * returns it as the Generator value, and a resume
     * `@manticore_<name>$resume(frame*)` — the state machine. Locals live in
     * the frame (survive suspension); the entry `switch (state)` re-enters at
     * the instruction after the last-executed yield. Returns 1 on a yield, 0
     * when the generator runs to completion.
     */
    private function emitGenerator(FunctionDef $fn): string
    {
        $mangled = '@manticore_' . $this->mangle($fn->name);
        $resume = $mangled . '$resume';

        // Frame slot index per local: params first, then body-assigned vars.
        $locals = [];
        foreach ($fn->params as $p) { $locals[$p->name] = \count($locals); }
        $this->collectGenLocals($fn->body, $locals);
        $frameSize = self::GEN_HEADER + 8 * \count($locals);

        // ── creator ──
        // A generator CLOSURE composes two frame mechanisms: it is invoked with
        // the closure ABI (`ptr %env` + declared args; captures unpacked from
        // the env struct), but it must allocate a generator frame and seed its
        // locals. Captures + declared params both become frame locals; the
        // captures are loaded from %env, the declared params from %arg.<name>.
        $capCnt = $this->closureCaptures[$fn->name] ?? -1;
        $isClosure = $capCnt >= 0;
        $capIndex = [];   // capture name → env slot index (0-based, +1 in struct)
        for ($pi = 0; $pi < ($isClosure ? $capCnt : 0); $pi = $pi + 1) {
            $capIndex[$fn->params[$pi]->name] = $pi;
        }
        $paramSig = '';
        $first = true;
        if ($isClosure) {
            $paramSig = 'ptr %env';
            $first = false;
            for ($pi = $capCnt; $pi < \count($fn->params); $pi = $pi + 1) {
                $paramSig .= ', i64 %arg.' . $fn->params[$pi]->name;
            }
        } else {
            foreach ($fn->params as $p) {
                if (!$first) { $paramSig .= ', '; }
                $first = false;
                $paramSig .= 'i64 %arg.' . $p->name;
            }
        }
        $defLinkage = $isClosure ? 'internal ' : '';
        $out = 'define ' . $defLinkage . 'i64 ' . $mangled . '(' . $paramSig . ") {\nentry:\n";
        // The frame carries the string-style rc header `[cap@-24, len@-16,
        // rc@-8, ...]` (value ptr = base+24) so a Generator is freed on its
        // last reference via the existing string rc helpers (rc@-8, free base =
        // ptr-24). This makes the frame buffer refcounted WITHOUT shifting any
        // gen-field offset (they stay at value+0…). rc starts at 1 (the
        // creator's owned ref). The 24-byte header also makes a generic-rc
        // misroute free the correct base. Inner rc-typed frame locals are not
        // yet dropped — a bounded residual leak (the frame buffer was the O(N)
        // dominant one). MUST match the string header size ({@see
        // __mir_str_alloc}) — the string release computes free base = ptr-24.
        $this->needsStrRc = true;
        $strHdr = \Compile\MemoryAbi::STRING_HEADER_SIZE;
        $base = $this->allocSsa();
        $out .= '  ' . $base . ' = call ptr @__mir_alloc(i64 ' . (string)($frameSize + $strHdr) . ")\n";
        $fr = $this->allocSsa();
        $out .= '  ' . $fr . ' = getelementptr inbounds i8, ptr ' . $base . ", i64 " . (string)$strHdr . "\n";
        $out .= $this->genStoreAt($fr, -24, '0');                     // cap@-24 = 0 (unused)
        $out .= $this->genStoreAt($fr, -16, '0');                     // len@-16 = 0 (unused)
        $out .= $this->genStoreAt($fr, -8, '1');                      // rc@-8 = 1
        $rp = $this->allocSsa();
        $out .= '  ' . $rp . ' = ptrtoint ptr ' . $resume . " to i64\n";
        $out .= '  store i64 ' . $rp . ', ptr ' . $fr . "\n";        // resume_fn@0
        $out .= $this->genStoreAt($fr, 8, '0');                       // state@8 = 0
        $out .= $this->genStoreAt($fr, 16, '0');                      // current@16 = 0
        $out .= $this->genStoreAt($fr, 24, '0');                      // key@24 = 0
        $out .= $this->genStoreAt($fr, 32, '0');                      // nextkey@32 = 0
        // sent@40: the inbound yield-expression value (cell-typed). Default to a
        // boxed null so an unsent `$x = yield` reads NULL (not raw 0) and
        // var_dump / echo render it correctly; send()/throw() box their arg.
        $this->needsTagged = true;
        $bn = $this->allocSsa();
        $out .= '  ' . $bn . " = call i64 @__manticore_box_null()\n";
        $out .= $this->genStoreAt($fr, 40, $bn);                      // sent@40 = null cell
        $out .= $this->genStoreAt($fr, 48, '0');                      // retval@48 = 0
        $paramNames = [];
        $paramTypeByName = [];
        foreach ($fn->params as $p) { $paramNames[$p->name] = true; $paramTypeByName[$p->name] = $p->type; }
        foreach ($locals as $name => $idx) {
            $off = self::GEN_HEADER + 8 * $idx;
            if (isset($capIndex[$name])) {
                // capture: load from env slot (capIndex+1), store into frame.
                $gep = $this->allocSsa();
                $out .= '  ' . $gep . ' = getelementptr inbounds i64, ptr %env, i64 '
                      . (string)($capIndex[$name] + 1) . "\n";
                $cv = $this->allocSsa();
                $out .= '  ' . $cv . ' = load i64, ptr ' . $gep . "\n";
                $out .= $this->genStoreAt($fr, $off, $cv);
            } elseif (isset($paramNames[$name])) {
                // A CLOSURE generator's caller (emitInvoke) boxed every scalar
                // arg to a cell — unbox a concrete-scalar param before seeding
                // the frame (the body reads the frame slot as that scalar). A
                // NAMED generator is called via emitCall, which passes a typed
                // scalar raw, so it seeds raw.
                $pt = $paramTypeByName[$name] ?? null;
                if ($isClosure && $pt !== null && $this->isCellScalarParam($pt)) {
                    $this->lastValue = '%arg.' . $name;
                    $this->lastValueType = 'i64';
                    $out .= $this->unboxCellToType($pt);
                    $out .= $this->coerceToI64();
                    $out .= $this->genStoreAt($fr, $off, $this->lastValue);
                } else {
                    $out .= $this->genStoreAt($fr, $off, '%arg.' . $name);
                }
            } else {
                $out .= $this->genStoreAt($fr, $off, '0');
            }
        }
        $ri = $this->allocSsa();
        $out .= '  ' . $ri . ' = ptrtoint ptr ' . $fr . " to i64\n";
        $out .= '  ret i64 ' . $ri . "\n}\n\n";

        // ── resume ──
        $this->slots = [];
        $this->refLocals = [];
        $this->currentReturnType = $fn->returnType;
        $out .= 'define ' . $defLinkage . 'i64 ' . $resume . "(ptr %frame) {\nentry:\n";
        // Local slots = frame GEPs computed in entry (dominate every block).
        foreach ($locals as $name => $idx) {
            $off = self::GEN_HEADER + 8 * $idx;
            $slot = $this->allocSsa();
            $out .= '  ' . $slot . ' = getelementptr inbounds i8, ptr %frame, i64 '
                  . (string)$off . "\n";
            $this->slots[$name] = $slot;
        }
        $this->genStatePtr = $this->allocSsa();
        $out .= '  ' . $this->genStatePtr . " = getelementptr inbounds i8, ptr %frame, i64 8\n";
        $this->genCurrentPtr = $this->allocSsa();
        $out .= '  ' . $this->genCurrentPtr . " = getelementptr inbounds i8, ptr %frame, i64 16\n";
        $this->genKeyPtr = $this->allocSsa();
        $out .= '  ' . $this->genKeyPtr . " = getelementptr inbounds i8, ptr %frame, i64 24\n";
        $this->genNextKeyPtr = $this->allocSsa();
        $out .= '  ' . $this->genNextKeyPtr . " = getelementptr inbounds i8, ptr %frame, i64 32\n";
        $this->genSentPtr = $this->allocSsa();
        $out .= '  ' . $this->genSentPtr . " = getelementptr inbounds i8, ptr %frame, i64 40\n";
        $this->genRetvalPtr = $this->allocSsa();
        $out .= '  ' . $this->genRetvalPtr . " = getelementptr inbounds i8, ptr %frame, i64 48\n";
        $st = $this->allocSsa();
        $out .= '  ' . $st . ' = load i64, ptr ' . $this->genStatePtr . "\n";
        $nYields = $this->countYields($fn->body);
        $startLabel = $this->allocLabel('gen.start');
        $cases = '';
        for ($k = 1; $k <= $nYields; $k = $k + 1) {
            $cases .= '    i64 ' . (string)$k . ', label %gen.resume.' . (string)$k . "\n";
        }
        $out .= '  switch i64 ' . $st . ', label %' . $startLabel . " [\n" . $cases . "  ]\n";
        $out .= $startLabel . ":\n";

        $savedInGen = $this->inGenerator;
        $savedCounter = $this->genYieldCounter;
        $this->inGenerator = true;
        $this->genYieldCounter = 0;
        $out .= $this->emitNode($fn->body);
        $this->inGenerator = $savedInGen;
        $this->genYieldCounter = $savedCounter;

        // Fell off the end → finished.
        $out .= '  store i64 -1, ptr ' . $this->genStatePtr . "\n";
        $out .= "  ret i64 0\n}\n\n";
        return $out;
    }

    /** `store i64 <val>, ptr (base + off)` — a frame header/local write. */
    private function genStoreAt(string $base, int $off, string $val): string
    {
        if ($off === 0) {
            return '  store i64 ' . $val . ', ptr ' . $base . "\n";
        }
        $p = $this->allocSsa();
        return '  ' . $p . ' = getelementptr inbounds i8, ptr ' . $base . ', i64 '
             . (string)$off . "\n  store i64 " . $val . ', ptr ' . $p . "\n";
    }

    /**
     * Collect generator-frame local names (body `StoreLocal` targets, plus
     * foreach value/key vars) into `$locals` (name → slot index), preserving
     * any already present (the params).
     * @param array<string,int> $locals
     */
    private function collectGenLocals(Node $n, array &$locals): void
    {
        if ($n->kind === Node::KIND_STORE_LOCAL) {
            $name = $this->castStoreLocal($n)->name;
            if (!isset($locals[$name])) { $locals[$name] = \count($locals); }
        } elseif ($n->kind === Node::KIND_TRY_CATCH) {
            $tc = $this->castTryCatch($n);
            // A catch variable (`catch (E $e)`) is bound by emitTryCatch via a
            // direct slot store, not a StoreLocal — collect it so it gets a
            // frame slot (else `$e` has no slot and `$e->m()` reads garbage).
            foreach ($tc->catches as $mc) {
                $cv = $this->catchVar($mc);
                if ($cv !== null && !isset($locals[$cv])) { $locals[$cv] = \count($locals); }
            }
            // Depth snapshots ($idb / $od) — a yield inside the try makes the
            // resume switch bypass the entry-block SSA, so they live in frame
            // slots (reloaded at the depth-restore points).
            $tc->genDepthSlot = \count($locals);
            $locals["@try.d." . (string)$tc->genDepthSlot] = \count($locals);
            if ($tc->hasFinally) {
                $tc->genOuterSlot = \count($locals);
                $locals["@try.o." . (string)$tc->genOuterSlot] = \count($locals);
                // Two cells: pending-flag + pending-value (finally rethrow).
                $tc->genPendSlot = \count($locals);
                $locals["@try.pf." . (string)$tc->genPendSlot] = \count($locals);
                $locals["@try.pv." . (string)$tc->genPendSlot] = \count($locals);
            }
        } elseif ($n->kind === Node::KIND_FOREACH) {
            $fe = $this->castForeach($n);
            if (!isset($locals[$fe->valueVar])) { $locals[$fe->valueVar] = \count($locals); }
            if ($fe->keyVar !== null && !isset($locals[$fe->keyVar])) {
                $locals[$fe->keyVar] = \count($locals);
            }
            // Iterator state that crosses a yield in the body must live in the
            // frame (the resume entry-switch re-enters mid-loop, killing SSA /
            // stack allocas). An ARRAY foreach needs two slots (cursor + array
            // ptr); a GENERATOR foreach needs one (the sub-generator ptr).
            if ($this->foreachBodyYields($fe->body)) {
                $fe->genSlotBase = \count($locals);
                $locals["@fe.0." . (string)$fe->genSlotBase] = \count($locals);
                if (!$this->isGeneratorType($fe->array->type)) {
                    $locals["@fe.1." . (string)$fe->genSlotBase] = \count($locals);
                }
            }
        }
        foreach (\Compile\Mir\Walk::children($n) as $c) {
            $this->collectGenLocals($c, $locals);
        }
    }

    /** True when a foreach body contains a `yield` (its iterator state then
     *  crosses a suspension and must live in the frame). */
    private function foreachBodyYields(Node $body): bool
    {
        return $this->countYields($body) > 0;
    }

    /** Count `yield` nodes in a generator body (state-machine arity). */
    private function countYields(Node $n): int
    {
        $c = $n->kind === Node::KIND_YIELD ? 1 : 0;
        foreach (\Compile\Mir\Walk::children($n) as $ch) {
            $c = $c + $this->countYields($ch);
        }
        return $c;
    }

    /**
     * `yield`, `yield $v`, `yield $k => $v` inside a resume body: store the
     * key + value into the frame, set `state` to this yield's index, and
     * `ret 1` (suspend). The matching `gen.resume.<k>` label (a switch
     * target) re-enters here; the yield EXPRESSION's value is then the value
     * passed in via `send()` (the frame's `sent` slot, 0/null otherwise).
     * The key is an explicit `$k` or the auto-increment `nextkey` counter.
     */
    private function emitYield(Node $n): string
    {
        if (!$this->inGenerator) {
            throw new \RuntimeException('EmitLlvm: yield outside a generator');
        }
        $y = $this->castYield($n);
        if ($y->from) {
            throw new \RuntimeException('EmitLlvm: `yield from` not yet implemented');
        }
        $out = '';
        $val = '0';
        if ($y->value !== null) {
            $out .= $this->emitNode($y->value);
            $out .= $this->coerceToI64();
            $val = $this->lastValue;
        }
        // Key: explicit `$k =>`, else the auto-increment counter (then bump it).
        if ($y->key !== null) {
            $out .= $this->emitNode($y->key);
            $out .= $this->coerceToI64();
            $out .= '  store i64 ' . $this->lastValue . ', ptr ' . $this->genKeyPtr . "\n";
        } else {
            $nk = $this->allocSsa();
            $out .= '  ' . $nk . ' = load i64, ptr ' . $this->genNextKeyPtr . "\n";
            $out .= '  store i64 ' . $nk . ', ptr ' . $this->genKeyPtr . "\n";
            $nk1 = $this->allocSsa();
            $out .= '  ' . $nk1 . ' = add i64 ' . $nk . ", 1\n";
            $out .= '  store i64 ' . $nk1 . ', ptr ' . $this->genNextKeyPtr . "\n";
        }
        $out .= '  store i64 ' . $val . ', ptr ' . $this->genCurrentPtr . "\n";
        $k = $this->genYieldCounter + 1;
        $this->genYieldCounter = $k;
        $out .= '  store i64 ' . (string)$k . ', ptr ' . $this->genStatePtr . "\n";
        $out .= "  ret i64 1\n";
        $out .= 'gen.resume.' . (string)$k . ":\n";
        // `$gen->throw($e)` injection: on resume, a pending exception makes the
        // suspended `yield` expression raise (caught by an enclosing try in the
        // generator, else propagated to the consumer via the jmp stack). The
        // longjmp targets depth-1 — the generator's own try setjmp (left at this
        // depth by the suspend; yield-ret doesn't pop it) or the consumer's.
        if ($this->genThrowUsed) {
            $gt = $this->allocSsa();
            $out .= '  ' . $gt . " = load ptr, ptr @__mir_gen_throw\n";
            $inj = $this->allocSsa();
            $out .= '  ' . $inj . ' = icmp ne ptr ' . $gt . ", null\n";
            $thrL = $this->allocLabel('gen.inject');
            $contL = $this->allocLabel('gen.resumed');
            $out .= '  br i1 ' . $inj . ', label %' . $thrL . ', label %' . $contL . "\n";
            $out .= $thrL . ":\n";
            $out .= "  store ptr null, ptr @__mir_gen_throw\n";
            $out .= '  store ptr ' . $gt . ", ptr @__mir_thrown\n";
            $d = $this->allocSsa();
            $out .= '  ' . $d . " = load i64, ptr @__mir_jmp_depth\n";
            $s = $this->allocSsa();
            $out .= '  ' . $s . ' = sub i64 ' . $d . ", 1\n";
            $out .= $this->jmpBufExpr($s);
            $out .= '  call void @longjmp(ptr ' . $this->jmpScratch . ", i32 1)\n";
            $out .= "  unreachable\n";
            $out .= $contL . ":\n";
        }
        // Resumed: the yield expression evaluates to the sent-in value.
        $sent = $this->allocSsa();
        $out .= '  ' . $sent . ' = load i64, ptr ' . $this->genSentPtr . "\n";
        $this->lastValue = $sent;
        $this->lastValueType = 'i64';
        return $out;
    }

    private function castYield(Node $n): \Compile\Mir\Yield_ { return $n; }

    /** A generator value (`@manticore_<gen>` creator result). */
    private function isGeneratorType(Type $t): bool
    {
        return $t->kind === Type::KIND_OBJ && ($t->class ?? '') === 'Generator';
    }

    /**
     * Whether `$class` (or an ancestor) implements interface `$iface`,
     * transitively through the parent chain and interface inheritance.
     * Built-in interfaces (Iterator, ArrayAccess, …) aren't in `$classes`;
     * they're matched by name as declared on `implements`.
     */
    private function classImplements(string $class, string $iface): bool
    {
        $seen = [];
        $stack = [$class];
        while ($stack !== []) {
            $c = \array_pop($stack);
            if ($c === '' || isset($seen[$c])) { continue; }
            $seen[$c] = true;
            if ($c === $iface) { return true; }
            $cd = $this->classes[$c] ?? null;
            if ($cd === null) { continue; }
            if ($cd->parent !== '') { $stack[] = $cd->parent; }
            foreach ($cd->interfaces as $i) { $stack[] = $i; }
        }
        return false;
    }

    /** A non-Generator object usable in foreach: implements Iterator or
     *  IteratorAggregate (Traversable). */
    private function isTraversableType(Type $t): bool
    {
        if ($t->kind !== Type::KIND_OBJ) { return false; }
        $c = $t->class ?? '';
        if ($c === '' || $c === 'Generator') { return false; }
        return $this->classImplements($c, 'Iterator')
            || $this->classImplements($c, 'IteratorAggregate')
            || $this->classImplements($c, 'Traversable');
    }

    /**
     * `foreach ($gen as [$k =>] $v)` — drive the generator's iterator
     * protocol. The Generator value is its frame ptr; the resume fn ptr lives
     * at frame@0 (called indirectly). rewind (resume once if state==0), then
     * loop while state != -1: read `current`@16 into the value var, run the
     * body, resume. Default keys are an auto-incrementing int counter.
     */
    private function emitForeachGenerator(\Compile\Mir\Foreach_ $fe): string
    {
        $out = '';
        if (!isset($this->slots[$fe->valueVar])) {
            $vs = $this->allocSsa();
            $this->slots[$fe->valueVar] = $vs;
            $out .= '  ' . $vs . " = alloca i64\n";
        }
        if ($fe->keyVar !== null && !isset($this->slots[$fe->keyVar])) {
            $ks = $this->allocSsa();
            $this->slots[$fe->keyVar] = $ks;
            $out .= '  ' . $ks . " = alloca i64\n";
        }
        $out .= $this->emitNode($fe->array);
        $out .= $this->coerceToPtr();
        $g = $this->lastValue;
        // Inside a generator the sub-generator ptr must survive the inner
        // yield (the resume entry-switch re-enters mid-loop), so stash it in a
        // frame slot and reload it in each block.
        $framed = $fe->genSlotBase >= 0;
        $gSlot = '';
        if ($framed) {
            $gSlot = $this->slots["@fe.0." . (string)$fe->genSlotBase];
            $gi = $this->allocSsa();
            $out .= '  ' . $gi . ' = ptrtoint ptr ' . $g . " to i64\n";
            $out .= '  store i64 ' . $gi . ', ptr ' . $gSlot . "\n";
        }

        $rewindLabel = $this->allocLabel('feg.rewind');
        $condLabel = $this->allocLabel('feg.cond');
        $bodyLabel = $this->allocLabel('feg.body');
        $stepLabel = $this->allocLabel('feg.step');
        $endLabel  = $this->allocLabel('feg.end');

        // rewind: resume once if not yet started (state == 0).
        $out .= $this->genFieldLoad($g, 8);
        $st0 = $this->lastValue;
        $fresh = $this->allocSsa();
        $out .= '  ' . $fresh . ' = icmp eq i64 ' . $st0 . ", 0\n";
        $out .= '  br i1 ' . $fresh . ', label %' . $rewindLabel . ', label %' . $condLabel . "\n";
        $out .= $rewindLabel . ":\n";
        $out .= $this->genResumeCall($g);
        $out .= '  br label %' . $condLabel . "\n";

        $out .= $condLabel . ":\n";
        if ($framed) { $out .= $this->genReloadArr($gSlot); $g = $this->lastValue; }
        $out .= $this->genFieldLoad($g, 8);
        $st = $this->lastValue;
        $fin = $this->allocSsa();
        $out .= '  ' . $fin . ' = icmp eq i64 ' . $st . ", -1\n";
        $out .= '  br i1 ' . $fin . ', label %' . $endLabel . ', label %' . $bodyLabel . "\n";

        $out .= $bodyLabel . ":\n";
        if ($framed) { $out .= $this->genReloadArr($gSlot); $g = $this->lastValue; }
        $out .= $this->genFieldLoad($g, 16);
        $cur = $this->lastValue;
        $out .= '  store i64 ' . $cur . ', ptr ' . $this->slots[$fe->valueVar] . "\n";
        if ($fe->keyVar !== null) {
            $out .= $this->genFieldLoad($g, 24);
            $out .= '  store i64 ' . $this->lastValue . ', ptr ' . $this->slots[$fe->keyVar] . "\n";
        }
        $savedBreak = $this->breakLabel;
        $savedCont  = $this->continueLabel;
        $this->breakLabel = $endLabel;
        $this->continueLabel = $stepLabel;
        $this->breakStack[] = $endLabel;
        $this->continueStack[] = $stepLabel;
        $out .= $this->emitNode($fe->body);
        \array_pop($this->breakStack);
        \array_pop($this->continueStack);
        $this->breakLabel = $savedBreak;
        $this->continueLabel = $savedCont;
        $out .= '  br label %' . $stepLabel . "\n";

        $out .= $stepLabel . ":\n";
        if ($framed) { $out .= $this->genReloadArr($gSlot); $g = $this->lastValue; }
        $out .= $this->genResumeCall($g);
        $out .= '  br label %' . $condLabel . "\n";

        $out .= $endLabel . ":\n";
        // A foreach subject that is an owned producer (`foreach (gen() as ...)`)
        // is a temp, not a tracked local — release its frame here so it's freed
        // (rc str-path). A borrowed local subject (`foreach ($g as ...)`) is
        // released at its own scope exit; releasing here would double-free.
        $ak = $fe->array->kind;
        if ($ak === Node::KIND_CALL || $ak === Node::KIND_METHOD_CALL
            || $ak === Node::KIND_STATIC_CALL || $ak === Node::KIND_INVOKE) {
            $relPtr = $g;
            if ($framed) { $out .= $this->genReloadArr($gSlot); $relPtr = $this->lastValue; }
            $this->needsStrRc = true;
            $out .= '  call void @__mir_rc_release_str(ptr ' . $relPtr . ")\n";
        }
        $this->lastValue = '0';
        $this->lastValueType = 'i64';
        return $out;
    }

    private int $iterCounter = 0;

    /**
     * `foreach ($obj as [$k =>] $v)` over a Traversable object — drive its
     * Iterator protocol via method calls. An IteratorAggregate yields its
     * `getIterator()` first. The iterator is held in a synthetic local slot;
     * each protocol call (rewind/valid/current/key/next) is a synthesized
     * {@see MethodCall_} routed through the normal (virtual) dispatch. Subject
     * type / value+key types were resolved by InferTypes onto the node.
     */
    private function emitForeachObject(\Compile\Mir\Foreach_ $fe): string
    {
        $out = '';
        if (!isset($this->slots[$fe->valueVar])) {
            $vs = $this->allocSsa();
            $this->slots[$fe->valueVar] = $vs;
            $out .= '  ' . $vs . " = alloca i64\n";
        }
        if ($fe->keyVar !== null && !isset($this->slots[$fe->keyVar])) {
            $ks = $this->allocSsa();
            $this->slots[$fe->keyVar] = $ks;
            $out .= '  ' . $ks . " = alloca i64\n";
        }
        // Hold the iterator in a synthetic local; protocol calls load it from
        // there so the subject expression is evaluated exactly once.
        $iterName = "@it." . (string)$this->iterCounter;
        $this->iterCounter = $this->iterCounter + 1;
        $iterSlot = $this->allocSsa();
        $this->slots[$iterName] = $iterSlot;
        $out .= '  ' . $iterSlot . " = alloca i64\n";
        $out .= $this->emitNode($fe->array);
        $out .= $this->coerceToI64();
        $out .= '  store i64 ' . $this->lastValue . ', ptr ' . $iterSlot . "\n";
        $iterType = \Compile\Mir\Type::obj($fe->iterClass);
        if ($fe->iterAggregate) {
            $subjNode = new \Compile\Mir\LoadLocal($iterName, $fe->array->type);
            $gi = new \Compile\Mir\MethodCall_($subjNode, 'getIterator', [], $iterType);
            $out .= $this->emitNode($gi);
            $out .= $this->coerceToI64();
            $out .= '  store i64 ' . $this->lastValue . ', ptr ' . $iterSlot . "\n";
        }
        $iterNode = new \Compile\Mir\LoadLocal($iterName, $iterType);

        $out .= $this->emitNode(new \Compile\Mir\MethodCall_($iterNode, 'rewind', [], \Compile\Mir\Type::void()));

        $condL = $this->allocLabel('feo.cond');
        $bodyL = $this->allocLabel('feo.body');
        $stepL = $this->allocLabel('feo.step');
        $endL  = $this->allocLabel('feo.end');
        $out .= '  br label %' . $condL . "\n";

        $out .= $condL . ":\n";
        $out .= $this->emitNode(new \Compile\Mir\MethodCall_($iterNode, 'valid', [], \Compile\Mir\Type::bool_()));
        $out .= $this->coerceToI64();
        $v = $this->allocSsa();
        $out .= '  ' . $v . ' = icmp ne i64 ' . $this->lastValue . ", 0\n";
        $out .= '  br i1 ' . $v . ', label %' . $bodyL . ', label %' . $endL . "\n";

        $out .= $bodyL . ":\n";
        $out .= $this->emitNode(new \Compile\Mir\MethodCall_($iterNode, 'current', [], \Compile\Mir\Type::unknown()));
        $out .= $this->coerceToI64();
        $out .= '  store i64 ' . $this->lastValue . ', ptr ' . $this->slots[$fe->valueVar] . "\n";
        if ($fe->keyVar !== null) {
            $out .= $this->emitNode(new \Compile\Mir\MethodCall_($iterNode, 'key', [], \Compile\Mir\Type::unknown()));
            $out .= $this->coerceToI64();
            $out .= '  store i64 ' . $this->lastValue . ', ptr ' . $this->slots[$fe->keyVar] . "\n";
        }
        $savedBreak = $this->breakLabel;
        $savedCont  = $this->continueLabel;
        $this->breakLabel = $endL;
        $this->continueLabel = $stepL;
        $this->breakStack[] = $endL;
        $this->continueStack[] = $stepL;
        $out .= $this->emitNode($fe->body);
        \array_pop($this->breakStack);
        \array_pop($this->continueStack);
        $this->breakLabel = $savedBreak;
        $this->continueLabel = $savedCont;
        $out .= '  br label %' . $stepL . "\n";

        $out .= $stepL . ":\n";
        $out .= $this->emitNode(new \Compile\Mir\MethodCall_($iterNode, 'next', [], \Compile\Mir\Type::void()));
        $out .= '  br label %' . $condL . "\n";

        $out .= $endL . ":\n";
        $this->lastValue = '0';
        $this->lastValueType = 'i64';
        return $out;
    }

    /** Indirectly call a generator's resume fn (ptr at frame@0). */
    private function genResumeCall(string $frame): string
    {
        $fnw = $this->allocSsa();
        $out  = '  ' . $fnw . ' = load i64, ptr ' . $frame . "\n";
        $fp = $this->allocSsa();
        $out .= '  ' . $fp . ' = inttoptr i64 ' . $fnw . " to ptr\n";
        $rr = $this->allocSsa();
        $out .= '  ' . $rr . ' = call i64 ' . $fp . '(ptr ' . $frame . ")\n";
        return $out;
    }

    /**
     * Emit an FFI function as a thin wrapper forwarding to its C symbol.
     * The outer signature is the uniform MIR ABI (i64 params / i64 return);
     * each arg is coerced from its i64 carrier to the extern's C type, the
     * extern is called, and its result coerced back to i64. The extern is
     * declared once (deduped via libcExtra). Call sites are unchanged —
     * they invoke `@manticore_<mangled>` like any other function.
     */
    private function emitFfiWrapper(FunctionDef $fn): string
    {
        $cSym = $fn->ffiSymbol;
        $ret = $fn->ffiRetCType;
        $paramSig = '';
        $first = true;
        foreach ($fn->params as $p) {
            if (!$first) { $paramSig .= ', '; }
            $first = false;
            $paramSig .= 'i64 %arg.' . $p->name;
        }
        $out = 'define i64 @manticore_' . $this->mangle($fn->name) . '(' . $paramSig . ") {\nentry:\n";
        $cargs = [];
        $idx = 0;
        foreach ($fn->params as $p) {
            $ct = $fn->ffiParamCTypes[$idx] ?? 'i64';
            $src = '%arg.' . $p->name;
            if ($ct === 'ptr') {
                $r = $this->allocSsa();
                $out .= '  ' . $r . ' = inttoptr i64 ' . $src . " to ptr\n";
                $cargs[] = 'ptr ' . $r;
            } elseif ($ct === 'double') {
                $r = $this->allocSsa();
                $out .= '  ' . $r . ' = bitcast i64 ' . $src . " to double\n";
                $cargs[] = 'double ' . $r;
            } elseif ($ct === 'i1') {
                $r = $this->allocSsa();
                $out .= '  ' . $r . ' = trunc i64 ' . $src . " to i1\n";
                $cargs[] = 'i1 ' . $r;
            } else {
                $cargs[] = 'i64 ' . $src;
            }
            $idx = $idx + 1;
        }
        // cli_argc/argv are DEFINED in the preamble (they read the argc/argv
        // captured by main), so don't also declare them — a declare + define
        // of one symbol is an LLVM redefinition error.
        if ($cSym !== 'manticore_cli_argc' && $cSym !== 'manticore_cli_argv') {
            $this->libcExtra[$cSym] = 'declare ' . $ret . ' @' . $cSym
                . '(' . \implode(', ', $fn->ffiParamCTypes) . ')';
        }
        $callArgs = \implode(', ', $cargs);
        if ($ret === 'void') {
            $out .= '  call void @' . $cSym . '(' . $callArgs . ")\n";
            $out .= "  ret i64 0\n";
        } else {
            $r = $this->allocSsa();
            $out .= '  ' . $r . ' = call ' . $ret . ' @' . $cSym . '(' . $callArgs . ")\n";
            if ($ret === 'ptr') {
                $ri = $this->allocSsa();
                $out .= '  ' . $ri . ' = ptrtoint ptr ' . $r . " to i64\n";
                $out .= '  ret i64 ' . $ri . "\n";
            } elseif ($ret === 'double') {
                $ri = $this->allocSsa();
                $out .= '  ' . $ri . ' = bitcast double ' . $r . " to i64\n";
                $out .= '  ret i64 ' . $ri . "\n";
            } elseif ($ret === 'i1') {
                $ri = $this->allocSsa();
                $out .= '  ' . $ri . ' = zext i1 ' . $r . " to i64\n";
                $out .= '  ret i64 ' . $ri . "\n";
            } else {
                $out .= '  ret i64 ' . $r . "\n";
            }
        }
        $out .= "}\n\n";
        return $out;
    }

    /**
     * B5 PGO metrics. Counter indices into the @__prof array:
     * 0 str_alloc, 1 str_retain, 2 str_release, 3 rc_retain (obj/vec),
     * 4 rc_release (obj/vec), 5 assoc_retain, 6 assoc_release.
     * Emitted only under `MANTICORE_PROFILE=1`; a no-op string otherwise so
     * production IR is byte-identical.
     */
    private function profBump(int $idx): string
    {
        if (!\Compile\Debug::$profile) { return ''; }
        return '  call void @__prof_bump(i64 ' . (string)$idx . ")\n";
    }

    /** The @__prof array + bump + atexit dump (preamble, profile mode only). */
    private function profileRuntime(): string
    {
        if (!\Compile\Debug::$profile) { return ''; }
        $out  = "@__prof = linkonce_odr global [14 x i64] zeroinitializer\n";
        $out .= "declare i32 @atexit(ptr)\n";
        $out .= "declare i32 @dprintf(i32, ptr, ...)\n";
        // 0-6: per-flavor alloc/retain/release. 7-13: retain by SOURCE category
        // (which call-site emitted it) so the over-retain can be targeted.
        $names = ['str_alloc', 'str_retain', 'str_release', 'rc_retain',
                  'rc_release', 'assoc_retain', 'assoc_release',
                  'retain_alias', 'retain_capture', 'retain_element',
                  'retain_assoc', 'retain_prop', 'retain_static', 'retain_return'];
        $i = 0;
        foreach ($names as $nm) {
            // Each escape (`\0A` newline, `\00` NUL) is one byte in the
            // array; the rest map 1:1 from the PHP literal, so the declared
            // length is just the printable-text length plus those two bytes.
            $text = '[PROFILE] ' . $nm . '=%lld';
            $byteLen = \strlen($text) + 2;
            $out .= '@__prof.fmt.' . (string)$i . ' = private unnamed_addr constant ['
                  . (string)$byteLen . ' x i8] c"' . $text . '\0A\00"' . "\n";
            $i = $i + 1;
        }
        $out .= "define void @__prof_bump(i64 %i) {\nentry:\n";
        $out .= "  %p = getelementptr inbounds [14 x i64], ptr @__prof, i64 0, i64 %i\n";
        $out .= "  %v = load i64, ptr %p\n";
        $out .= "  %v1 = add i64 %v, 1\n";
        $out .= "  store i64 %v1, ptr %p\n";
        $out .= "  ret void\n}\n";
        $out .= "define void @__manticore_profile_dump() {\nentry:\n";
        for ($j = 0; $j < 14; $j = $j + 1) {
            $out .= '  %p' . (string)$j . ' = getelementptr inbounds [14 x i64], ptr @__prof, i64 0, i64 '
                  . (string)$j . "\n";
            $out .= '  %v' . (string)$j . ' = load i64, ptr %p' . (string)$j . "\n";
            $out .= '  call i32 (i32, ptr, ...) @dprintf(i32 2, ptr @__prof.fmt.'
                  . (string)$j . ', i64 %v' . (string)$j . ")\n";
        }
        $out .= "  ret void\n}\n";
        return $out;
    }

    /**
     * `@__mir_uncaught()` — the top-level fatal handler an uncaught throw
     * longjmps to (base setjmp installed in @main). Renders PHP's
     * `PHP Fatal error:  Uncaught <Class>: <message>` to stderr and exits 255.
     * Class name comes from a runtime class_id switch; the message is the
     * Throwable's first property (`message`, same offset for every Throwable).
     */
    /** True if `$n` (or a descendant) throws or has a try-catch. */
    private function scanUsesExceptions(Node $n): bool
    {
        if ($n->kind === Node::KIND_THROW || $n->kind === Node::KIND_TRY_CATCH) {
            return true;
        }
        foreach (\Compile\Mir\Walk::children($n) as $c) {
            if ($this->scanUsesExceptions($c)) { return true; }
        }
        return false;
    }

    private function emitUncaughtHandler(): string
    {
        $this->libcExtra['exit'] = 'declare void @exit(i32)';
        $this->libcExtra['dprintf'] = 'declare i32 @dprintf(i32, ptr, ...)';
        // message offset: the Exception layout is shared by every Throwable.
        $exc = $this->classes['Exception'] ?? null;
        $msgOff = $exc !== null ? $exc->propertyOffset('message') : 16;
        $strs = '';
        $cases = '';
        $bodies = '';
        foreach ($this->classes as $cls) {
            if ($cls->isStruct) { continue; }
            $id = (string)$cls->classId;
            $sym = '@__mir_ucn_' . $id;
            $strs .= $this->strGlobalDef($sym, $cls->name);
            $cases .= '    i64 ' . $id . ', label %uc_' . $id . "\n";
            $bodies .= 'uc_' . $id . ":\n"
                . '  store ptr ' . $this->strSymBytes($sym) . ", ptr %cn\n"
                . "  br label %named\n";
        }
        $strs .= $this->strGlobalDef('@__mir_ucn_def', 'Exception');
        $empty = $this->strSymBytes('@.cstr.empty');
        $out = $strs;
        $out .= "define void @__mir_uncaught() {\nentry:\n";
        $out .= "  %cn = alloca ptr\n";
        $out .= '  store ptr ' . $this->strSymBytes('@__mir_ucn_def') . ", ptr %cn\n";
        $out .= "  %e = load ptr, ptr @__mir_thrown\n";
        $out .= "  %z = icmp eq ptr %e, null\n";
        $out .= "  br i1 %z, label %named, label %have\n";
        $out .= "have:\n";
        $out .= "  %descI = load i64, ptr %e\n";
        $out .= "  %descp = inttoptr i64 %descI to ptr\n";
        $out .= "  %cid = load i64, ptr %descp\n";
        $out .= "  switch i64 %cid, label %named [\n" . $cases . "  ]\n";
        $out .= $bodies;
        $out .= "named:\n";
        $out .= "  %cname = load ptr, ptr %cn\n";
        $out .= "  %haveE = icmp ne ptr %e, null\n";
        $out .= "  br i1 %haveE, label %msg, label %print\n";
        $out .= "msg:\n";
        $out .= '  %mp = getelementptr i8, ptr %e, i64 ' . (string)$msgOff . "\n";
        $out .= "  %msgv = load ptr, ptr %mp\n";
        $out .= "  %mnz = icmp ne ptr %msgv, null\n";
        $out .= '  %msgf = select i1 %mnz, ptr %msgv, ptr ' . $empty . "\n";
        $out .= "  br label %print\n";
        $out .= "print:\n";
        $out .= '  %m = phi ptr [ ' . $empty . ', %named ], [ %msgf, %msg ]' . "\n";
        $out .= "  call i32 (i32, ptr, ...) @dprintf(i32 2, ptr @.fmt.uncaught, ptr %cname, ptr %m)\n";
        $out .= "  call void @exit(i32 255)\n";
        $out .= "  unreachable\n}\n";
        return $out;
    }

    private function emitMain(FunctionDef $fn): string
    {
        $this->needsCliArgv = true;
        $header = "define i32 @main(i32 %argc, ptr %argv) {\nentry:\n";
        if ($this->needsExceptions) {
            // Install the base landing pad: depth 1 reserves slot 0 for this
            // catch-all, so user tries take slots 1+ and a throw that escapes
            // them all unwinds here instead of computing an OOB slot -1.
            $header .= "  store i64 1, ptr @__mir_jmp_depth\n";
            $header .= "  %__basebuf = getelementptr inbounds i8, ptr @__mir_jmp_stack, i64 0\n";
            $header .= "  %__basesj = call i32 @setjmp(ptr %__basebuf)\n";
            $header .= "  %__caught = icmp ne i32 %__basesj, 0\n";
            $header .= "  br i1 %__caught, label %__uncaught, label %__run\n";
            $header .= "__uncaught:\n";
            $header .= "  call void @__mir_uncaught()\n";
            $header .= "  unreachable\n";
            $header .= "__run:\n";
        }
        if (\Compile\Debug::$profile) {
            $header .= "  call i32 @atexit(ptr @__manticore_profile_dump)\n";
        }
        // Capture argc/argv into module globals so the FFI-bound
        // manticore_cli_argc/argv (Main.php #[Symbol]) can read them.
        $ac = $this->allocSsa();
        $header .= '  ' . $ac . ' = sext i32 %argc to i64' . "\n";
        $header .= '  store i64 ' . $ac . ", ptr @__manticore_argc\n";
        $header .= "  store ptr %argv, ptr @__manticore_argv\n";
        $body = $this->preallocateLocals($fn->body);
        $body .= $this->initRcObjSlots($fn->body);
        $body .= $this->emitNode($fn->body);
        $body .= "  ret i32 0\n";
        return $header . $body . "}\n\n";
    }

    /**
     * Register the owned RcHeap obj locals (the plan's rc_release ops)
     * for the current function and null-init their slots, so a release on
     * a path where the local was never assigned is a no-op rather than a
     * read of garbage.
     */
    private function initRcObjSlots(Node $body, array $paramNames = []): string
    {
        $this->currentRcObjLocals = [];
        $this->collectRcObjLocals($body);
        $this->currentParamNames = $paramNames;
        $this->transferredLocals = [];
        $this->collectTransferredLocals($body);
        $this->elementSharedLocals = [];
        $this->collectElementSharedLocals($body);
        $out = '';
        foreach ($this->currentRcObjLocals as $name => $mo) {
            // A reassigned obj/str/vec/assoc PARAM holds the caller's
            // incoming (borrowed) value. The first `$p = ...` reassignment
            // emits a release-before-overwrite of that old value, and a
            // no-return path releases it at scope exit — both would
            // over-release the caller's reference (a double-free, e.g.
            // `$fqn = ltrim($fqn)` in parseUseDecl). Retain it once on
            // entry so the frame co-owns the slot; the matching release
            // then cancels cleanly. (Slot already holds the incoming arg.)
            if (isset($paramNames[$name])) {
                if (isset($this->slots[$name])) {
                    $out .= $this->rcRetainSlot($this->slots[$name], $this->rcReleaseFlavor($mo));
                }
                continue;
            }
            if (isset($this->slots[$name])) {
                $out .= '  store i64 0, ptr ' . $this->slots[$name] . "\n";
            }
        }
        return $out;
    }

    private function collectRcObjLocals(Node $n): void
    {
        if ($n->kind === Node::KIND_MEMORY_OP) {
            $mo = $this->castMemoryOp($n);
            if ($mo->op === 'rc_release' && $mo->target !== null
                && $mo->target->kind === Node::KIND_LOAD_LOCAL) {
                // Store the MemoryOp node, not its flavor string — the
                // self-host backend corrupts a short string round-tripped
                // through an assoc value (a `'str'` read back mis-compares),
                // but a node handle survives. Flavor is re-derived per use.
                $this->currentRcObjLocals[$this->castLoadLocal($mo->target)->name] = $mo;
            }
            return;
        }
        foreach (\Compile\Mir\Walk::children($n) as $c) { $this->collectRcObjLocals($c); }
    }

    /**
     * B2 escape pre-pass: find owned rcObj locals whose value flows into a
     * BORROWING container store and record them in {@see $transferredLocals}.
     * A "borrowing" store is one where {@see containerStoreRetains} is false —
     * the value's type is erased and the container offers no usable element /
     * property fallback, so the store writes a borrowed reference WITHOUT a
     * retain. Releasing such a local at scope exit over-releases (the
     * container still references it) — the enum/arena heisenbug. Suppressing
     * the release moves ownership to the container instead (leak-safe).
     */
    private function collectTransferredLocals(Node $n): void
    {
        $k = $n->kind;
        if ($k === Node::KIND_STORE_ELEMENT) {
            $se = $this->castStoreElement($n);
            // assoc store retains by its element type; vec offers no fallback.
            $fallback = $se->array->type->isAssoc()
                ? ($se->array->type->element ?? null) : null;
            $this->maybeTransfer($se->value, $fallback);
        } elseif ($k === Node::KIND_STORE_PROPERTY) {
            $sp = $this->castStoreProperty($n);
            $pcls = $sp->object->type->class ?? '';
            $propType = ($pcls !== '' && isset($this->classes[$pcls]))
                ? ($this->classes[$pcls]->propertyTypes[$sp->property] ?? null)
                : null;
            $this->maybeTransfer($sp->value, $propType);
        } elseif ($k === Node::KIND_ARRAY_LIT) {
            $al = $this->castArrayLit($n);
            $fallback = $al->type->element ?? null;
            foreach ($al->elements as $el) { $this->maybeTransfer($el->value, $fallback); }
        }
        foreach (\Compile\Mir\Walk::children($n) as $c) { $this->collectTransferredLocals($c); }
    }

    /**
     * Mark `$valueNode`'s source local as transferred iff it is an owned
     * rcObj local stored through a borrowing container store. Params are
     * excluded (retained-on-entry, so suppressing their release unbalances
     * the entry retain). Only the no-retain case transfers — a retaining
     * store keeps the local's release (it is balanced by the container drop).
     */
    private function maybeTransfer(Node $valueNode, ?Type $fallback): void
    {
        if ($valueNode->kind !== Node::KIND_LOAD_LOCAL) { return; }
        $name = $this->castLoadLocal($valueNode)->name;
        if (!isset($this->currentRcObjLocals[$name])) { return; }
        if (isset($this->currentParamNames[$name])) { return; }
        if ($this->containerStoreRetains($valueNode, $fallback)) { return; }
        $this->transferredLocals[$name] = true;
    }

    /**
     * Escape pre-pass for element-drop suppression: find owned vec/assoc
     * locals whose buffer is passed BY VALUE to a FACTORY — a call that
     * returns an object (or a `new`) — so the constructed node stores and
     * co-owns the buffer plus its retained element refs (the +1 each
     * `array_append` adds). The local's scope-exit release must then drop the
     * buffer only — see {@see $elementSharedLocals}. This is the parser
     * `$args = parseArgList(); return Expr::call(..., $args, ...)` shape.
     *
     * Gated on an OBJECT result on purpose: a scalar/array-returning callee
     * (`count`, `implode`, `array_map`) READS the buffer without keeping it,
     * so a sole-owned confined vec passed there must keep its element-drop
     * (else its elements leak). A false positive here only leaks (the safe
     * direction); element-drop on a genuinely co-owned buffer would UAF.
     */
    private function collectElementSharedLocals(Node $n): void
    {
        $k = $n->kind;
        if ($k === Node::KIND_NEW_OBJ) {
            $this->shareCallArgs($this->castNewObj($n)->args);
        } elseif ($n->type->kind === Type::KIND_OBJ) {
            if ($k === Node::KIND_CALL) {
                $this->shareCallArgs($this->castCall($n)->args);
            } elseif ($k === Node::KIND_METHOD_CALL) {
                $this->shareCallArgs($this->castMethodCall($n)->args);
            } elseif ($k === Node::KIND_STATIC_CALL) {
                $this->shareCallArgs($this->castStaticCall($n)->args);
            } elseif ($k === Node::KIND_INVOKE) {
                $this->shareCallArgs($this->castInvoke($n)->args);
            }
        }
        foreach (\Compile\Mir\Walk::children($n) as $c) { $this->collectElementSharedLocals($c); }
    }

    /** @param Node[] $args */
    private function shareCallArgs(array $args): void
    {
        foreach ($args as $a) {
            if ($a->kind !== Node::KIND_LOAD_LOCAL) { continue; }
            $t = $a->type;
            if (!$t->isVec() && !$t->isAssoc()) { continue; }
            $el = $t->element;
            if ($el === null) { continue; }
            if ($el->kind !== Type::KIND_OBJ && $el->kind !== Type::KIND_STRING) { continue; }
            $this->elementSharedLocals[$this->castLoadLocal($a)->name] = true;
        }
    }

    /**
     * Mirror of {@see rcRetainByType}'s gate for a borrow (LoadLocal) value:
     * whether the container co-owns it with a retain. True iff the value's
     * effective type (own type, or the container fallback when erased) is a
     * non-struct, non-closure rc kind. When false the store borrows (no
     * retain) and ownership must transfer to avoid the over-release.
     */
    private function containerStoreRetains(Node $valueNode, ?Type $fallback): bool
    {
        $tk = $valueNode->type->kind;
        $cls = $valueNode->type->class ?? '';
        if (($tk === Type::KIND_UNKNOWN || $tk === Type::KIND_CELL) && $fallback !== null) {
            $fk = $fallback->kind;
            if ($fk === Type::KIND_OBJ || $fk === Type::KIND_ARRAY
                || $fk === Type::KIND_STRING) {
                $tk = $fk;
                $cls = $fallback->class ?? '';
            }
        }
        if ($tk !== Type::KIND_OBJ && $tk !== Type::KIND_ARRAY
            && $tk !== Type::KIND_STRING) { return false; }
        if ($tk === Type::KIND_OBJ) {
            if ($cls !== '' && isset($this->classes[$cls]) && $this->classes[$cls]->isStruct) { return false; }
            if ($this->isClosureClass($cls)) { return false; }
            if ($this->isEnumClass($cls)) { return false; }
        }
        return true;
    }

    /**
     * Walks the function body looking for `StoreLocal` nodes and
     * returns the alloca chunk for the entry block. Subsequent
     * stores / loads address through `$this->slots[$name]`.
     *
     * Self-host pre-scan doesn't propagate `string &$body` writes
     * through nested method calls; returning the chunk and concat-
     * at-call-site is the workaround that holds.
     */
    /**
     * Pre-scan: mark every vec local that is mutated (append or element
     * store) in the function. Drives copy-on-assign value semantics — a
     * `$b = $a` between vecs only needs an independent copy when one of
     * them is later mutated; pure read-only aliases share safely.
     */
    private function collectMutatedVecs(Node $n): void
    {
        if ($n->kind === Node::KIND_STORE_ELEMENT) {
            $arr = $this->castStoreElement($n)->array;
            if ($arr->kind === Node::KIND_LOAD_LOCAL
                && $arr->type->isArray()) {
                $this->mutatedVecLocals[$this->castLoadLocal($arr)->name] = true;
            }
        }
        // Taking an element's ADDRESS by reference (a `$a[$k]` bound via RefAddr_
        // or passed as a call argument that may be by-ref) can mutate the vec —
        // mark it so a prior `$b = $a` copy-on-assigns instead of sharing the
        // buffer the reference will write through. Over-approximate (any call
        // arg): a needless copy is safe, a shared write is not.
        if ($n->kind === Node::KIND_REF_ADDR) {
            $this->markVecElemBase($this->castRefAddr($n)->lvalue);
        }
        if ($n->kind === Node::KIND_CALL) {
            foreach ($this->castCall($n)->args as $a) { $this->markVecElemBase($a); }
        }
        if ($n->kind === Node::KIND_METHOD_CALL) {
            foreach ($this->castMethodCall($n)->args as $a) { $this->markVecElemBase($a); }
        }
        if ($n->kind === Node::KIND_STATIC_CALL) {
            foreach ($this->castStaticCall($n)->args as $a) { $this->markVecElemBase($a); }
        }
        foreach (\Compile\Mir\Walk::children($n) as $c) {
            $this->collectMutatedVecs($c);
        }
    }

    /** Whether `$t` is an array whose element is a SCALAR / string (a flat
     *  `__mir_array_copy` fully separates it). A nested-array / object / cell
     *  element is shared by the flat copy, so it is NOT safe to copy-on-entry. */
    private function isScalarElemArray(Type $t): bool
    {
        $e = $t->element;
        if ($e === null) { return false; }
        $k = $e->kind;
        return $k === Type::KIND_INT || $k === Type::KIND_FLOAT
            || $k === Type::KIND_STRING || $k === Type::KIND_BOOL;
    }

    /** Mark the array local under an `$a[$k]` element as mutated (its element may
     *  be written through a reference). No-op for non-element / non-array-local. */
    private function markVecElemBase(Node $a): void
    {
        if ($a->kind !== Node::KIND_ARRAY_ACCESS) { return; }
        $arr = $this->castArrayAccess($a)->array;
        if ($arr->kind === Node::KIND_LOAD_LOCAL && $arr->type->isArray()) {
            $this->mutatedVecLocals[$this->castLoadLocal($arr)->name] = true;
        }
    }

    /**
     * Pre-scan: register every static-local name → its global cell so
     * Load/StoreLocal route to the cell and preallocateLocals skips an
     * alloca. Recurses through structured control flow.
     */
    private function collectStaticLocals(Node $n): void
    {
        $k = $n->kind;
        if ($k === Node::KIND_STATIC_LOCAL_DECL) {
            $sld = $this->castStaticLocalDecl($n);
            $this->globalBackedLocals[$sld->name] = $sld->cell;
            return;
        }
        if ($k === Node::KIND_BLOCK) {
            foreach ($this->castBlock($n)->stmts as $s) { $this->collectStaticLocals($s); }
            return;
        }
        if ($k === Node::KIND_IF) {
            $i = $this->castIf($n);
            $this->collectStaticLocals($i->then);
            if ($i->else !== null) { $this->collectStaticLocals($i->else); }
            return;
        }
        if ($k === Node::KIND_WHILE) {
            $this->collectStaticLocals($this->castWhile($n)->body);
            return;
        }
        if ($k === Node::KIND_FOR) {
            $this->collectStaticLocals($this->castFor($n)->body);
            return;
        }
        if ($k === Node::KIND_DOWHILE) {
            $this->collectStaticLocals($this->castDoWhile($n)->body);
            return;
        }
        if ($k === Node::KIND_FOREACH) {
            $this->collectStaticLocals($this->castForeach($n)->body);
            return;
        }
        if ($k === Node::KIND_SWITCH) {
            foreach ($this->castSwitch($n)->arms as $arm) {
                foreach ($arm->body as $s) { $this->collectStaticLocals($s); }
            }
            return;
        }
    }

    private function preallocateLocals(Node $n): string
    {
        $k = $n->kind;
        if ($k === Node::KIND_STORE_LOCAL) {
            $sl = $this->castStoreLocal($n);
            $out = '';
            if (!isset($this->globalBackedLocals[$sl->name]) && !isset($this->slots[$sl->name])) {
                $slot = $this->allocSsa();
                $this->slots[$sl->name] = $slot;
                $out .= '  ' . $slot . " = alloca i64\n";
            }
            return $out . $this->preallocateLocals($sl->value);
        }
        if ($k === Node::KIND_BLOCK) {
            $out = '';
            foreach ($this->castBlock($n)->stmts as $s) { $out .= $this->preallocateLocals($s); }
            return $out;
        }
        if ($k === Node::KIND_THROW) {
            return $this->preallocateLocals($this->castThrow($n)->value);
        }
        if ($k === Node::KIND_TRY_CATCH) {
            $tc = $this->castTryCatch($n);
            $out = '';
            foreach ($tc->tryBody as $s) { $out .= $this->preallocateLocals($s); }
            foreach ($tc->catches as $c) {
                $cVar = $this->catchVar($c);
                if ($cVar !== null && !isset($this->slots[$cVar])) {
                    $slot = $this->allocSsa();
                    $this->slots[$cVar] = $slot;
                    $out .= '  ' . $slot . " = alloca i64\n";
                }
                foreach ($this->catchBody($c) as $s) { $out .= $this->preallocateLocals($s); }
            }
            foreach ($tc->finallyBody as $s) { $out .= $this->preallocateLocals($s); }
            return $out;
        }
        if ($k === Node::KIND_IF) {
            $i = $this->castIf($n);
            $out = $this->preallocateLocals($i->cond);
            $out .= $this->preallocateLocals($i->then);
            if ($i->else !== null) { $out .= $this->preallocateLocals($i->else); }
            return $out;
        }
        if ($k === Node::KIND_WHILE) {
            $w = $this->castWhile($n);
            return $this->preallocateLocals($w->cond) . $this->preallocateLocals($w->body);
        }
        if ($k === Node::KIND_FOR) {
            $f = $this->castFor($n);
            $out = '';
            if ($f->init !== null) { $out .= $this->preallocateLocals($f->init); }
            if ($f->cond !== null) { $out .= $this->preallocateLocals($f->cond); }
            if ($f->step !== null) { $out .= $this->preallocateLocals($f->step); }
            return $out . $this->preallocateLocals($f->body);
        }
        if ($k === Node::KIND_DOWHILE) {
            $d = $this->castDoWhile($n);
            return $this->preallocateLocals($d->body) . $this->preallocateLocals($d->cond);
        }
        if ($k === Node::KIND_FOREACH) {
            $fe = $this->castForeach($n);
            $out = $this->preallocateLocals($fe->array);
            // Hoist the value/key slots to entry so a foreach nested in a
            // branch doesn't leave its slot alloca dominating only that
            // branch (two sibling foreaches reusing `$val` then break LLVM).
            if (!isset($this->slots[$fe->valueVar])) {
                $vs = $this->allocSsa();
                $this->slots[$fe->valueVar] = $vs;
                $out .= '  ' . $vs . " = alloca i64\n";
            }
            if ($fe->keyVar !== null && !isset($this->slots[$fe->keyVar])) {
                $ks = $this->allocSsa();
                $this->slots[$fe->keyVar] = $ks;
                $out .= '  ' . $ks . " = alloca i64\n";
            }
            return $out . $this->preallocateLocals($fe->body);
        }
        if ($k === Node::KIND_ADD || $k === Node::KIND_SUB || $k === Node::KIND_MUL
            || $k === Node::KIND_MOD || $k === Node::KIND_CMP) {
            return $this->preallocateLocals($this->binLeft($n))
                 . $this->preallocateLocals($this->binRight($n));
        }
        if ($k === Node::KIND_NEG) { return $this->preallocateLocals($this->castNeg($n)->operand); }
        if ($k === Node::KIND_NOT) { return $this->preallocateLocals($this->castNot($n)->operand); }
        if ($k === Node::KIND_BITOP) {
            $b = $this->castBitOp($n);
            return $this->preallocateLocals($b->left) . $this->preallocateLocals($b->right);
        }
        if ($k === Node::KIND_BITNOT) { return $this->preallocateLocals($this->castBitNot($n)->operand); }
        if ($k === Node::KIND_CONCAT) {
            $c = $this->castConcat($n);
            return $this->preallocateLocals($c->left) . $this->preallocateLocals($c->right);
        }
        if ($k === Node::KIND_CAST) {
            return $this->preallocateLocals($this->castCast($n)->operand);
        }
        if ($k === Node::KIND_NULLCOALESCE) {
            $nc = $this->castNullCoalesce($n);
            return $this->preallocateLocals($nc->left) . $this->preallocateLocals($nc->right);
        }
        if ($k === Node::KIND_INVOKE) {
            $iv = $this->castInvoke($n);
            $out = $this->preallocateLocals($iv->callee);
            foreach ($iv->args as $a) { $out .= $this->preallocateLocals($a); }
            return $out;
        }
        if ($k === Node::KIND_TERNARY) {
            $t = $this->castTernary($n);
            $out = $this->preallocateLocals($t->cond);
            if ($t->then !== null) { $out .= $this->preallocateLocals($t->then); }
            return $out . $this->preallocateLocals($t->else_);
        }
        if ($k === Node::KIND_SWITCH) {
            $sw = $this->castSwitch($n);
            $out = $this->preallocateLocals($sw->subject);
            foreach ($sw->arms as $arm) {
                if ($arm->value !== null) { $out .= $this->preallocateLocals($arm->value); }
                foreach ($arm->body as $s) { $out .= $this->preallocateLocals($s); }
            }
            return $out;
        }
        if ($k === Node::KIND_MATCH) {
            $m = $this->castMatch($n);
            $out = $this->preallocateLocals($m->subject);
            foreach ($m->arms as $arm) {
                $conds = $arm->conds;
                if ($conds !== null) {
                    foreach ($conds as $c) { $out .= $this->preallocateLocals($c); }
                }
                $out .= $this->preallocateLocals($arm->body);
            }
            return $out;
        }
        if ($k === Node::KIND_ECHO) {
            $out = '';
            foreach ($this->castEcho($n)->exprs as $e) { $out .= $this->preallocateLocals($e); }
            return $out;
        }
        if ($k === Node::KIND_RETURN) {
            $v = $this->castReturn($n)->value;
            return $v === null ? '' : $this->preallocateLocals($v);
        }
        if ($k === Node::KIND_CALL) {
            $out = '';
            foreach ($this->castCall($n)->args as $a) { $out .= $this->preallocateLocals($a); }
            return $out;
        }
        if ($k === Node::KIND_ARRAY_LIT) {
            $out = '';
            foreach ($this->castArrayLit($n)->elements as $el) {
                if ($el->key !== null) { $out .= $this->preallocateLocals($el->key); }
                $out .= $this->preallocateLocals($el->value);
            }
            return $out;
        }
        if ($k === Node::KIND_ARRAY_ACCESS) {
            $aa = $this->castArrayAccess($n);
            return $this->preallocateLocals($aa->array) . $this->preallocateLocals($aa->index);
        }
        if ($k === Node::KIND_STORE_ELEMENT) {
            $se = $this->castStoreElement($n);
            return $this->preallocateLocals($se->array)
                 . $this->preallocateLocals($se->index)
                 . $this->preallocateLocals($se->value);
        }
        if ($k === Node::KIND_NEW_OBJ) {
            $out = '';
            foreach ($this->castNewObj($n)->args as $a) { $out .= $this->preallocateLocals($a); }
            return $out;
        }
        if ($k === Node::KIND_CLONE) {
            $cl = $this->castClone($n);
            $out = $this->preallocateLocals($cl->object);
            foreach ($cl->withProps as $pair) { $out .= $this->preallocateLocals($pair->value); }
            return $out;
        }
        if ($k === Node::KIND_PROPERTY_ACCESS) {
            return $this->preallocateLocals($this->castPropertyAccess($n)->object);
        }
        if ($k === Node::KIND_STORE_PROPERTY) {
            $sp = $this->castStoreProperty($n);
            return $this->preallocateLocals($sp->object) . $this->preallocateLocals($sp->value);
        }
        if ($k === Node::KIND_METHOD_CALL) {
            $mc = $this->castMethodCall($n);
            $out = $this->preallocateLocals($mc->object);
            foreach ($mc->args as $a) { $out .= $this->preallocateLocals($a); }
            return $out;
        }
        return '';
    }

    private string $lastValue = '0';
    private string $lastValueType = 'i64';

    private function emitNode(Node $n): string
    {
        $k = $n->kind;
        if ($k === Node::KIND_INT_CONST)    { $this->lastValue = (string)$this->castIntConst($n)->value; $this->lastValueType = 'i64'; return ''; }
        if ($k === Node::KIND_FLOAT_CONST)  { $this->lastValue = $this->formatFloat($this->castFloatConst($n)->value); $this->lastValueType = 'double'; return ''; }
        if ($k === Node::KIND_BOOL_CONST)   { $this->lastValue = $this->castBoolConst($n)->value ? '1' : '0'; $this->lastValueType = 'i64'; return ''; }
        if ($k === Node::KIND_NULL_CONST)   { $this->lastValue = '0'; $this->lastValueType = 'i64'; return ''; }
        if ($k === Node::KIND_STRING_CONST) { return $this->emitStringConst($n); }
        if ($k === Node::KIND_LOAD_LOCAL)   { return $this->emitLoadLocal($n); }
        if ($k === Node::KIND_STORE_LOCAL)  { return $this->emitStoreLocal($n); }
        if ($k === Node::KIND_ADD)          { return $this->emitArith($n, $this->binLeft($n), $this->binRight($n), 'add', 'fadd'); }
        if ($k === Node::KIND_SUB)          { return $this->emitArith($n, $this->binLeft($n), $this->binRight($n), 'sub', 'fsub'); }
        if ($k === Node::KIND_MUL)          { return $this->emitArith($n, $this->binLeft($n), $this->binRight($n), 'mul', 'fmul'); }
        if ($k === Node::KIND_DIV)          { return $this->emitDiv($n); }
        if ($k === Node::KIND_MOD)          { return $this->emitArith($n, $this->binLeft($n), $this->binRight($n), 'srem', 'frem'); }
        if ($k === Node::KIND_NEG)          { return $this->emitNeg($n); }
        if ($k === Node::KIND_NOT)          { return $this->emitNot($n); }
        if ($k === Node::KIND_BITOP)        { return $this->emitBitOp($n); }
        if ($k === Node::KIND_BITNOT)       { return $this->emitBitNot($n); }
        if ($k === Node::KIND_CAST)         { return $this->emitCast($n); }
        if ($k === Node::KIND_INSTANCEOF)   { return $this->emitInstanceof($n); }
        if ($k === Node::KIND_NULLCOALESCE) { return $this->emitNullCoalesce($n); }
        if ($k === Node::KIND_CLOSURE)      { return $this->emitClosure($n); }
        if ($k === Node::KIND_INVOKE)       { return $this->emitInvoke($n); }
        if ($k === Node::KIND_YIELD)        { return $this->emitYield($n); }
        if ($k === Node::KIND_INCDEC)       { return $this->emitIncDec($n); }
        if ($k === Node::KIND_STATIC_PROP)  { return $this->emitStaticProp($n); }
        if ($k === Node::KIND_STORE_STATIC_PROP) { return $this->emitStoreStaticProp($n); }
        if ($k === Node::KIND_STATIC_LOCAL_DECL) { return $this->emitStaticLocalDecl($n); }
        if ($k === Node::KIND_ISSET)        { return $this->emitIsset($n); }
        if ($k === Node::KIND_UNSET)        { return $this->emitUnset($n); }
        if ($k === Node::KIND_CLASS_NAME)   { return $this->emitClassName($n); }
        if ($k === Node::KIND_REF_ALIAS)    { return $this->emitRefAlias($n); }
        if ($k === Node::KIND_REF_BIND)     { return $this->emitRefBind($n); }
        if ($k === Node::KIND_REF_ADDR)     { return $this->emitRefAddr($n); }
        if ($k === Node::KIND_THROW)        { return $this->emitThrow($n); }
        if ($k === Node::KIND_TRY_CATCH)    { return $this->emitTryCatch($n); }
        if ($k === Node::KIND_TERNARY)      { return $this->emitTernary($n); }
        if ($k === Node::KIND_CONCAT)       { return $this->emitConcat($n); }
        if ($k === Node::KIND_CMP)          { return $this->emitCmp($n); }
        if ($k === Node::KIND_ECHO)         { return $this->emitEcho($n); }
        if ($k === Node::KIND_RETURN)       { return $this->emitReturn($n); }
        if ($k === Node::KIND_CALL)         { return $this->emitCall($n); }
        if ($k === Node::KIND_IF)           { return $this->emitIf($n); }
        if ($k === Node::KIND_WHILE)        { return $this->emitWhile($n); }
        if ($k === Node::KIND_FOR)          { return $this->emitFor($n); }
        if ($k === Node::KIND_DOWHILE)      { return $this->emitDoWhile($n); }
        if ($k === Node::KIND_SWITCH)       { return $this->emitSwitch($n); }
        if ($k === Node::KIND_MATCH)        { return $this->emitMatch($n); }
        if ($k === Node::KIND_FOREACH)      { return $this->emitForeach($n); }
        if ($k === Node::KIND_BREAK)        { return '  br label %' . $this->breakTargetFor($this->castBreak($n)->level) . "\n" . $this->emitDeadLabel(); }
        if ($k === Node::KIND_CONTINUE)     { return '  br label %' . $this->continueTargetFor($this->castContinue($n)->level) . "\n" . $this->emitDeadLabel(); }
        if ($k === Node::KIND_GOTO)         { return '  br label %' . $this->userLabel($this->castGoto($n)->label) . "\n" . $this->emitDeadLabel(); }
        if ($k === Node::KIND_LABEL)        { $l = $this->userLabel($this->castLabel($n)->name); return '  br label %' . $l . "\n" . $l . ":\n"; }
        if ($k === Node::KIND_ARRAY_LIT)    { return $this->emitArrayLit($n); }
        if ($k === Node::KIND_ARRAY_ACCESS) { return $this->emitArrayAccess($n); }
        if ($k === Node::KIND_STORE_ELEMENT){ return $this->emitStoreElement($n); }
        if ($k === Node::KIND_NEW_OBJ)         { return $this->emitNewObj($n); }
        if ($k === Node::KIND_CLONE)           { return $this->emitClone($n); }
        if ($k === Node::KIND_PROPERTY_ACCESS) { return $this->emitPropertyAccess($n); }
        if ($k === Node::KIND_STORE_PROPERTY)  { return $this->emitStoreProperty($n); }
        if ($k === Node::KIND_DYN_PROP)        { return $this->emitDynProp($n); }
        if ($k === Node::KIND_STORE_DYN_PROP)  { return $this->emitStoreDynProp($n); }
        if ($k === Node::KIND_METHOD_CALL)     { return $this->emitMethodCall($n); }
        if ($k === Node::KIND_STATIC_CALL)     { return $this->emitStaticCall($n); }
        if ($k === Node::KIND_BLOCK) {
            $out = '';
            foreach ($this->castBlock($n)->stmts as $s) {
                $out .= $this->emitNode($s);
                $out .= $this->emitDiscardedCallRelease($s);
            }
            return $out;
        }
        // MemoryOps consumer seam (#5). EmitLlvm reads the plan; it
        // never invents memory ops. Arena scope enter/leave emit real
        // runtime calls (#5b). RcHeap retain/release + rc-mode release
        // are still no-ops until the rc runtime lands.
        if ($k === Node::KIND_MEMORY_OP) { return $this->emitMemoryOp($n); }
        return '';
    }

    /**
     * After an unconditional terminator (`br`, `ret`) the next
     * instructions still need to live in a labeled block, otherwise
     * LLVM rejects the IR. Emit a fresh dead label callers fall
     * through into.
     */
    private function emitDeadLabel(): string
    {
        $label = $this->allocLabel('dead');
        return $label . ":\n";
    }

    private function emitStringConst(Node $n): string
    {
        $sc = $this->castStringConst($n);
        $id = $this->internString($sc->value);
        $this->lastValue = $this->strLitId($id);
        $this->lastValueType = 'ptr';
        return '';
    }

    private function emitLoadLocal(Node $n): string
    {
        $ll = $this->castLoadLocal($n);
        if (isset($this->globalBackedLocals[$ll->name])) {
            $reg = $this->allocSsa();
            $out = '  ' . $reg . ' = load i64, ptr ' . $this->globalBackedLocals[$ll->name] . "\n";
            if ($ll->type->kind === Type::KIND_FLOAT) {
                $regF = $this->allocSsa();
                $out .= '  ' . $regF . ' = bitcast i64 ' . $reg . " to double\n";
                $this->lastValue = $regF;
                $this->lastValueType = 'double';
            } else {
                $this->lastValue = $reg;
                $this->lastValueType = 'i64';
            }
            return $out;
        }
        if (!isset($this->slots[$ll->name])) {
            $this->lastValue = '0';
            $this->lastValueType = 'i64';
            return '';
        }
        $reg = $this->allocSsa();
        if (isset($this->refLocals[$ll->name])) {
            // By-ref: slot holds the address; deref to the value.
            $addr = $this->allocSsa();
            $out = '  ' . $addr . ' = load i64, ptr ' . $this->slots[$ll->name] . "\n";
            $p = $this->allocSsa();
            $out .= '  ' . $p . ' = inttoptr i64 ' . $addr . " to ptr\n";
            $out .= '  ' . $reg . ' = load i64, ptr ' . $p . "\n";
        } else {
            $out = '  ' . $reg . ' = load i64, ptr ' . $this->slots[$ll->name] . "\n";
        }
        // Slots are uniform i64. Bitcast back to double when the
        // inferred type for this local says it carries a float —
        // gives downstream `fadd` / `fdiv` a usable operand.
        if ($ll->type->kind === Type::KIND_FLOAT) {
            $regF = $this->allocSsa();
            $out .= '  ' . $regF . ' = bitcast i64 ' . $reg . " to double\n";
            $this->lastValue = $regF;
            $this->lastValueType = 'double';
        } else {
            $this->lastValue = $reg;
            $this->lastValueType = 'i64';
            // An obj-typed load whose value flow-narrowed from a cell/`mixed`
            // (param, foreach value, or a cell-returning call's result) still
            // carries the NaN tag in its slot — strip it so `->prop` / dispatch
            // gets a clean ptr. The mask is IDENTITY on a real heap pointer
            // (< 2^48), so it's a safe no-op for a genuine object local too.
            if ($ll->type->kind === Type::KIND_OBJ) {
                $masked = $this->allocSsa();
                $out .= '  ' . $masked . ' = and i64 ' . $reg . ", 281474976710655\n";
                $this->lastValue = $masked;
            }
        }
        return $out;
    }

    private function emitStoreLocal(Node $n): string
    {
        $sl = $this->castStoreLocal($n);
        // Amortized `.=`: `$s = $s . rhs` on a plain (non-ref, non-global,
        // non-arena) heap string local → in-place append instead of a fresh
        // O(n²) concat. The helper owns the old value's lifetime, so this
        // path deliberately skips the standard release-before-overwrite.
        $sv = $sl->value;
        // NB: no ARENA gate here — a `$s = $s . …` accumulator ESCAPES across a
        // loop back-edge, so even if InferAllocKind confined the concat, it must
        // become a heap str_append: str_append converts the (immortal-rc) arena
        // buffer to a heap copy on the first append, then mutates in place. That
        // also lets the per-iteration arena reset free the small operand temps,
        // so an arena-confined self-concat no longer grows the arena unbounded.
        if ($sv->kind === Node::KIND_CONCAT
            && $sv->type->kind === Type::KIND_STRING
            && !isset($this->refLocals[$sl->name])
            && !isset($this->globalBackedLocals[$sl->name])
            && isset($this->slots[$sl->name])) {
            // Flatten `$s = $s . a . b . …` (left-nested, so the outer concat's
            // left is a nested concat, NOT `$s`) to its leaves; if the first leaf
            // is `$s`, rebuild the suffix `a.b.…` as ONE right-hand concat and
            // reuse emitSelfAppend (str_append of a prebuilt rhs). Without this a
            // multi-way self-concat missed the append fast path AND leaked: the
            // general StoreLocal release-before-overwrite drops only owned obj/vec
            // locals, never a string, so the old accumulator was never freed
            // (O(n²) memory + time — json/sprintf builders).
            $ops = [];
            $this->flattenConcat($sv, $ops);
            $ops = $this->mergeAdjacentStrConsts($ops);
            if (\count($ops) >= 2
                && $ops[0]->kind === Node::KIND_LOAD_LOCAL
                && $ops[0]->type->kind === Type::KIND_STRING
                && $this->castLoadLocal($ops[0])->name === $sl->name) {
                $rest = $ops[1];
                $k = \count($ops);
                for ($j = 2; $j < $k; $j = $j + 1) {
                    $rest = new \Compile\Mir\Concat($rest, $ops[$j]);
                }
                return $this->emitSelfAppend($sl, new \Compile\Mir\Concat($ops[0], $rest));
            }
        }
        // Flow-sensitive cell-merge box-back (`$x = box($x)` planted by
        // InferTypes::planMergeShadow at an if/else merge): a store NODE typed
        // cell whose VALUE is concrete. That combo is otherwise impossible
        // (inferStoreLocal always types a store = its value type), so it is a
        // precise signal — box the concrete value into the slot, making it a
        // self-describing cell past the merge. No effect on any genuine cell
        // store (those have a cell value → fall through to the raw path).
        if ($sl->type->kind === Type::KIND_CELL
            && $sl->value->type->kind !== Type::KIND_CELL
            && !isset($this->refLocals[$sl->name])
            && !isset($this->globalBackedLocals[$sl->name])
            && isset($this->slots[$sl->name])) {
            $out = $this->emitNode($sl->value);
            $out .= $this->boxToCell($sl->value->type);
            $boxed = $this->lastValue;
            $out .= '  store i64 ' . $boxed . ', ptr ' . $this->slots[$sl->name] . "\n";
            $this->lastValue = $boxed;
            $this->lastValueType = 'i64';
            return $out;
        }
        // Float-slot local storing an int/bool value (`$s = 0` init before a
        // float accumulator): convert numerically (sitofp), then bit-store into
        // the i64 slot — else the integer bits land in a slot read as a double.
        // The (float store node, int value) combo is planted by InferTypes'
        // float-slot analysis; a genuine float store has a float value and falls
        // through to the raw path.
        if ($sl->type->kind === Type::KIND_FLOAT
            && ($sl->value->type->kind === Type::KIND_INT || $sl->value->type->kind === Type::KIND_BOOL)
            && !isset($this->refLocals[$sl->name])
            && !isset($this->globalBackedLocals[$sl->name])
            && isset($this->slots[$sl->name])) {
            $out = $this->emitNode($sl->value);
            $out .= $this->coerceToI64();
            $d = $this->allocSsa();
            $out .= '  ' . $d . ' = sitofp i64 ' . $this->lastValue . " to double\n";
            $bits = $this->allocSsa();
            $out .= '  ' . $bits . ' = bitcast double ' . $d . " to i64\n";
            $out .= '  store i64 ' . $bits . ', ptr ' . $this->slots[$sl->name] . "\n";
            $this->lastValue = $bits;
            $this->lastValueType = 'i64';
            return $out;
        }
        $this->vecAllocArena = false;
        $out = $this->emitNode($sl->value);
        // The value just emitted an arena vec → this local owns it, so
        // its `$x[] =` appends must realloc through the arena.
        if ($this->vecAllocArena) {
            $this->arenaVecLocals[$sl->name] = true;
        }
        // PHP arrays are values: `$b = $a` (vec OR assoc) needs an independent
        // copy when either side is later mutated, else a store into one would
        // clobber the other's shared buffer. Read-only aliases share safely
        // (`mutatedVecLocals` only records mutated locals). Objects are by-handle
        // (never copied); strings immutable. __mir_array_copy is mode-agnostic.
        $v = $sl->value;
        if ($v->kind === Node::KIND_LOAD_LOCAL
            && $v->type->isArray()
            && (isset($this->mutatedVecLocals[$this->castLoadLocal($v)->name])
                || isset($this->mutatedVecLocals[$sl->name]))) {
            $out .= $this->coerceToPtr();
            $src = $this->lastValue;
            $cp = $this->allocSsa();
            $out .= '  ' . $cp . ' = call ptr @__mir_array_copy(ptr ' . $src . ")\n";
            $this->lastValue = $cp;
            $this->lastValueType = 'ptr';
            // The copy is heap-owned + independent, so it is no longer an
            // arena vec alias.
            unset($this->arenaVecLocals[$sl->name]);
        }
        // `$saved = $this->vecProp` — snapshot of a vec PROPERTY. PHP value
        // semantics: it must be independent, else a later `$this->vecProp[]=…`
        // (emitVecAppend reallocs the property buffer in place) dangles the
        // snapshot — the property-side analogue of the assoc snapshot UAF and
        // the root of the enum_backed heisenbug. A property read can't be
        // proven unmutated here, so copy unconditionally (matches PHP, which
        // copies on every array assignment).
        if ($v->kind === Node::KIND_PROPERTY_ACCESS
            && $v->type->isVec()) {
            $out .= $this->coerceToPtr();
            $src = $this->lastValue;
            $cp = $this->allocSsa();
            $out .= '  ' . $cp . ' = call ptr @__mir_array_copy(ptr ' . $src . ")\n";
            $this->lastValue = $cp;
            $this->lastValueType = 'ptr';
        }
        // `$m = $obj` / `$b = $s` — a second owner of a by-handle object or
        // string. Retain so the source local's scope-exit release can't free
        // it early. (rcRetainByType no-ops an immortal literal.) NOTE: a local
        // assoc alias (`$b = $a`) is deliberately NOT retained here — the
        // assoc COW snapshot case we need is the PROPERTY one below; blanket-
        // retaining every local assoc alias added a spurious assoc_retain in
        // hot ctors (ClassDef) that, on a value whose buffer abuts a live heap
        // string, wrote rc into the string (the enum backing "int"→"jnt").
        $aliasObjStr = $v->kind === Node::KIND_LOAD_LOCAL
            && ($v->type->kind === Type::KIND_OBJ || $v->type->kind === Type::KIND_STRING);
        // `$saved = $this->map` — a snapshot of an assoc PROPERTY. Co-own it
        // too (rc>1) so a later `$this->map[$k]=…` copy-on-writes instead of
        // clobbering the snapshot's shared buffer (the InferTypes localTypes
        // snapshot UAF). Restricted to assoc — obj/string property reads have
        // their own retain discipline elsewhere.
        $aliasAssocProp = $v->kind === Node::KIND_PROPERTY_ACCESS
            && $v->type->isAssoc();
        if ($aliasObjStr || $aliasAssocProp) {
            $out .= $this->coerceToI64();
            $aliasV = $this->lastValue;
            $out .= $this->rcRetainByType($v, $aliasV, null, 0);
            $this->lastValue = $aliasV;
            $this->lastValueType = 'i64';
        }
        $val = $this->lastValue;
        // Coerce float values back into the slot's i64 cell with a
        // bitcast. Pointers (strings) ptrtoint similarly so the
        // i64 slot stays the universal carrier.
        if ($this->lastValueType === 'double') {
            $reg = $this->allocSsa();
            $out .= '  ' . $reg . ' = bitcast double ' . $val . " to i64\n";
            $val = $reg;
        } elseif ($this->lastValueType === 'ptr') {
            $reg = $this->allocSsa();
            $out .= '  ' . $reg . ' = ptrtoint ptr ' . $val . " to i64\n";
            $val = $reg;
        }
        if (isset($this->globalBackedLocals[$sl->name])) {
            $out .= '  store i64 ' . $val . ', ptr ' . $this->globalBackedLocals[$sl->name] . "\n";
        } elseif (isset($this->refLocals[$sl->name])) {
            $addr = $this->allocSsa();
            $out .= '  ' . $addr . ' = load i64, ptr ' . $this->slots[$sl->name] . "\n";
            $p = $this->allocSsa();
            $out .= '  ' . $p . ' = inttoptr i64 ' . $addr . " to ptr\n";
            $out .= '  store i64 ' . $val . ', ptr ' . $p . "\n";
        } else {
            // Release-before-overwrite: rebinding an owned RcHeap obj/vec
            // local drops its previous value (the slot is null-inited, so
            // the first store releases null = no-op). Frees the per-
            // iteration value in `for (...) { $x = new Foo(); }`.
            if (isset($this->currentRcObjLocals[$sl->name])
                && !isset($this->transferredLocals[$sl->name])) {
                $out .= $this->rcReleaseSlot($this->slots[$sl->name],
                    $this->rcReleaseFlavor($this->currentRcObjLocals[$sl->name]));
            }
            $out .= '  store i64 ' . $val . ', ptr ' . $this->slots[$sl->name] . "\n";
        }
        $this->lastValue = $val;
        $this->lastValueType = 'i64';
        return $out;
    }

    /**
     * Emit `$s .= rhs` as an in-place amortized append. Evaluates `rhs`,
     * loads the current accumulator, calls `__mir_str_append`, frees a
     * fresh `rhs` temp, and stores the result WITHOUT a release-before-
     * overwrite (the helper already released the old buffer on the grow
     * path / kept it on the in-place path). See {@see strAppendImpl}.
     */
    private function emitSelfAppend(StoreLocal $sl, Concat $c): string
    {
        $this->needsStrAppend = true;
        $this->needsStrRc = true;
        $this->needsConcat = true; // pulls strlen + the string runtime decls
        $slot = $this->slots[$sl->name];
        $out = $this->emitNode($c->right);
        $out .= $this->coerceToStr($c->right, false);
        $rp = $this->lastValue;
        $curI = $this->allocSsa();
        $out .= '  ' . $curI . ' = load i64, ptr ' . $slot . "\n";
        $curP = $this->allocSsa();
        $out .= '  ' . $curP . ' = inttoptr i64 ' . $curI . " to ptr\n";
        $reg = $this->allocSsa();
        $out .= '  ' . $reg . ' = call ptr @__mir_str_append(ptr ' . $curP
              . ', ptr ' . $rp . ")\n";
        // A freshly-produced rhs (coercion temp / nested concat / call) is
        // copied into the accumulator and now dead; a borrow is left alone.
        $out .= $this->concatTempRelease($c->right, $rp);
        $ri = $this->allocSsa();
        $out .= '  ' . $ri . ' = ptrtoint ptr ' . $reg . " to i64\n";
        $out .= '  store i64 ' . $ri . ', ptr ' . $slot . "\n";
        $this->lastValue = $ri;
        $this->lastValueType = 'i64';
        return $out;
    }

    /**
     * Coerce `$this->lastValue` (current $lastValueType) to the
     * given target type. Emits an instruction when a real cast is
     * needed; otherwise returns ''.
     */
    private function coerceTo(string $target): string
    {
        if ($this->lastValueType === $target) { return ''; }
        if ($target === 'double' && $this->lastValueType === 'i64') {
            $reg = $this->allocSsa();
            $out = '  ' . $reg . ' = sitofp i64 ' . $this->lastValue . " to double\n";
            $this->lastValue = $reg;
            $this->lastValueType = 'double';
            return $out;
        }
        if ($target === 'i64' && $this->lastValueType === 'double') {
            $reg = $this->allocSsa();
            $out = '  ' . $reg . ' = fptosi double ' . $this->lastValue . " to i64\n";
            $this->lastValue = $reg;
            $this->lastValueType = 'i64';
            return $out;
        }
        return '';
    }

    private function emitArith(Node $self, Node $left, Node $right, string $intOp, string $floatOp): string
    {
        // A numeric (int|float) cell result is dynamically int-or-float — its
        // runtime tag decides — so route to __manticore_tagged_<op>: box both
        // operands, the helper promotes to float iff either is float and
        // re-boxes a cell. Only a NUMERIC cell (Type::isNumericCell, from an
        // int|float union); a plain mixed cell never reaches here.
        if ($self->type->isNumericCell()) {
            return $this->emitTaggedArith($left, $right, $intOp);
        }
        $isFloat = $self->type->kind === Type::KIND_FLOAT;
        $target = $isFloat ? 'double' : 'i64';
        $op = $isFloat ? $floatOp : $intOp;
        $out = $this->emitNode($left);
        $out .= $this->coerceArithOperand($left, $isFloat);
        $l = $this->lastValue;
        $out .= $this->emitNode($right);
        $out .= $this->coerceArithOperand($right, $isFloat);
        $r = $this->lastValue;
        $reg = $this->allocSsa();
        $out .= '  ' . $reg . ' = ' . $op . ' ' . $target . ' ' . $l . ', ' . $r . "\n";
        $this->lastValue = $reg;
        $this->lastValueType = $target;
        return $out;
    }

    /** `$left <op> $right` where the result is a numeric (int|float) cell: box
     *  both operands to tagged cells and call the runtime helper, which promotes
     *  to float iff either is float and re-boxes a cell. */
    private function emitTaggedArith(Node $left, Node $right, string $op): string
    {
        $this->needsTaggedArith = true;
        $this->needsTagged = true;
        $this->needsTaggedToInt = true;
        $this->needsStrtol = true;
        $this->needsTaggedToFloat = true;
        $this->needsStrtod = true;
        $out = $this->emitNode($left);
        $out .= $this->boxToCell($left->type);
        $l = $this->lastValue;
        $out .= $this->emitNode($right);
        $out .= $this->boxToCell($right->type);
        $r = $this->lastValue;
        $reg = $this->allocSsa();
        $out .= '  ' . $reg . ' = call i64 @__manticore_tagged_' . $op
              . '(i64 ' . $l . ', i64 ' . $r . ")\n";
        $this->lastValue = $reg;
        $this->lastValueType = 'i64';
        return $out;
    }

    /** Coerce a just-emitted arithmetic operand: to double for a float op
     *  (unboxing a cell via tagged_to_double, not bitcasting its tagged bits),
     *  to i64 + unboxCellInt for an integer op. */
    private function coerceArithOperand(Node $op, bool $isFloat): string
    {
        if ($isFloat) {
            return $this->coerceDoubleOperand($op);
        }
        // A STRING operand in integer arithmetic is PHP-coerced to its leading
        // numeric value (strtol base 10), NOT ptrtoint'd — else `"2026" + "06"`
        // adds the raw pointers. (`explode(...)[i] + …` is the canonical case.)
        if ($op->type->kind === Type::KIND_STRING) {
            $this->needsStrtol = true;
            $out = $this->coerceToPtr();
            $reg = $this->allocSsa();
            $out .= '  ' . $reg . ' = call i64 @strtol(ptr ' . $this->lastValue . ', ptr null, i32 10)' . "\n";
            $this->lastValue = $reg;
            $this->lastValueType = 'i64';
            return $out;
        }
        $out = $this->coerceTo('i64');
        if ($op->type->kind === Type::KIND_CELL) {
            $out .= $this->unboxCellInt($this->lastValue);
        }
        return $out;
    }

    /** A just-emitted operand → double; a cell goes through tagged_to_double
     *  (int→sitofp, float→bits, …) instead of bitcasting the NaN-boxed bits. */
    private function coerceDoubleOperand(Node $op): string
    {
        // A STRING operand in float arithmetic → its numeric value via strtod
        // (PHP numeric-string coercion), not a bitcast of the pointer.
        if ($op->type->kind === Type::KIND_STRING) {
            $this->needsStrtod = true;
            $out = $this->coerceToPtr();
            $reg = $this->allocSsa();
            $out .= '  ' . $reg . ' = call double @strtod(ptr ' . $this->lastValue . ', ptr null)' . "\n";
            $this->lastValue = $reg;
            $this->lastValueType = 'double';
            return $out;
        }
        if ($op->type->kind !== Type::KIND_CELL) {
            return $this->coerceTo('double');
        }
        $this->needsTaggedToFloat = true;
        $this->needsStrtod = true;
        $out = $this->coerceToI64();
        $reg = $this->allocSsa();
        $out .= '  ' . $reg . ' = call double @__manticore_tagged_to_double(i64 ' . $this->lastValue . ")\n";
        $this->lastValue = $reg;
        $this->lastValueType = 'double';
        return $out;
    }

    /**
     * Unbox a tagged-cell i64 carrier to its signed int payload (false →
     * 0) via __manticore_unbox_int, leaving the result in lastValue. For
     * `int|false` (strpos) operands feeding integer arithmetic/comparison.
     */
    private function unboxCellInt(string $v): string
    {
        $this->needsTagged = true;
        $u = $this->allocSsa();
        $this->lastValue = $u;
        $this->lastValueType = 'i64';
        return '  ' . $u . ' = call i64 @__manticore_unbox_int(i64 ' . $v . ")\n";
    }

    /**
     * Emit `$a` as a plain i64 for a builtin arg that expects an integer
     * (substr offset/length, …). A tagged-cell operand — e.g. a `strpos`
     * result carried as `int|false` — is unboxed; the builtin handlers
     * emit args directly, bypassing the call loop's {@see unboxCellArg}.
     */
    private function emitIntArg(Node $a): string
    {
        $out = $this->emitNode($a);
        $out .= $this->coerceToI64();
        if ($a->type->kind === Type::KIND_CELL) {
            $out .= $this->unboxCellInt($this->lastValue);
        }
        return $out;
    }

    /**
     * PHP `/` always evaluates to float. Coerce both operands to
     * double, fdiv. Integer floor division goes through `intdiv`
     * separately — not surfaced as a MIR node yet.
     */
    private function emitDiv(Node $n): string
    {
        $d = $this->castDiv($n);
        $out = $this->emitNode($d->left);
        $out .= $this->coerceDoubleOperand($d->left);
        $l = $this->lastValue;
        $out .= $this->emitNode($d->right);
        $out .= $this->coerceDoubleOperand($d->right);
        $r = $this->lastValue;
        $reg = $this->allocSsa();
        $out .= '  ' . $reg . ' = fdiv double ' . $l . ', ' . $r . "\n";
        $this->lastValue = $reg;
        $this->lastValueType = 'double';
        return $out;
    }

    private function emitNeg(Node $n): string
    {
        $neg = $this->castNeg($n);
        // A numeric (int|float) cell is dynamic — negate as tagged `0 - $x` so a
        // float value keeps its float tag and the result stays a numeric cell.
        // The operand is ALREADY a boxed cell, so it is passed to tagged_sub
        // as-is (not re-boxed, which would double-tag).
        if ($neg->operand->type->isNumericCell()) {
            $this->needsTaggedArith = true;
            $this->needsTagged = true;
            $this->needsTaggedToInt = true;
            $this->needsStrtol = true;
            $this->needsTaggedToFloat = true;
            $this->needsStrtod = true;
            $out = $this->emitNode($neg->operand);
            $out .= $this->coerceToI64();
            $xc = $this->lastValue;
            $z = $this->allocSsa();
            $out .= '  ' . $z . " = call i64 @__manticore_box_int(i64 0)\n";
            $reg = $this->allocSsa();
            $out .= '  ' . $reg . ' = call i64 @__manticore_tagged_sub(i64 ' . $z
                  . ', i64 ' . $xc . ")\n";
            $this->lastValue = $reg;
            $this->lastValueType = 'i64';
            return $out;
        }
        $out = $this->emitNode($neg->operand);
        // Float negation needs fneg, not integer `sub i64 0, x` (the
        // operand carries a double — e.g. `-PHP_FLOAT_MAX`).
        if ($this->lastValueType === 'double' || $neg->operand->type->kind === Type::KIND_FLOAT) {
            $out .= $this->coerceTo('double');
            $reg = $this->allocSsa();
            $out .= '  ' . $reg . ' = fneg double ' . $this->lastValue . "\n";
            $this->lastValue = $reg;
            $this->lastValueType = 'double';
            return $out;
        }
        // Unbox a cell operand (and numeric-coerce a string) BEFORE the integer
        // negate — negating the raw NaN-boxed bits of `-$x` on a mixed/untyped
        // param produced garbage. Mirrors {@see coerceArithOperand}.
        $out .= $this->coerceArithOperand($neg->operand, false);
        $reg = $this->allocSsa();
        $out .= '  ' . $reg . ' = sub i64 0, ' . $this->lastValue . "\n";
        $this->lastValue = $reg;
        $this->lastValueType = 'i64';
        return $out;
    }

    private function emitNot(Node $n): string
    {
        $out = $this->emitCondVal($this->castNot($n)->operand);
        $val = $this->lastValue;
        $cmpReg = $this->allocSsa();
        $out .= '  ' . $cmpReg . ' = icmp eq i64 ' . $val . ", 0\n";
        $extReg = $this->allocSsa();
        $out .= '  ' . $extReg . ' = zext i1 ' . $cmpReg . " to i64\n";
        $this->lastValue = $extReg;
        return $out;
    }

    private function emitBitOp(Node $n): string
    {
        $b = $this->castBitOp($n);
        $out = $this->emitNode($b->left);
        $out .= $this->coerceToI64();
        $l = $this->lastValue;
        $out .= $this->emitNode($b->right);
        $out .= $this->coerceToI64();
        $r = $this->lastValue;
        // PHP `>>` is an arithmetic (sign-extending) shift → ashr.
        $op = $b->op;
        $ll = 'and';
        if ($op === 'shl')      { $ll = 'shl'; }
        elseif ($op === 'shr')  { $ll = 'ashr'; }
        elseif ($op === 'or')   { $ll = 'or'; }
        elseif ($op === 'xor')  { $ll = 'xor'; }
        $reg = $this->allocSsa();
        $out .= '  ' . $reg . ' = ' . $ll . ' i64 ' . $l . ', ' . $r . "\n";
        $this->lastValue = $reg;
        $this->lastValueType = 'i64';
        return $out;
    }

    private function emitBitNot(Node $n): string
    {
        $out = $this->emitNode($this->castBitNot($n)->operand);
        $out .= $this->coerceToI64();
        $val = $this->lastValue;
        $reg = $this->allocSsa();
        $out .= '  ' . $reg . ' = xor i64 ' . $val . ", -1\n";
        $this->lastValue = $reg;
        $this->lastValueType = 'i64';
        return $out;
    }

    private function castBitOp(Node $n): \Compile\Mir\BitOp { return $n; }
    private function castBitNot(Node $n): \Compile\Mir\BitNot_ { return $n; }

    /**
     * `$x instanceof T` → 1/0. The set of matching class_ids is fixed
     * at compile time (T + descendants, or classes implementing the
     * interface T, or — for `Stringable` — classes with `__toString`);
     * emit `load class_id` then an OR-chain of `icmp eq`.
     */
    /**
     * `$a ?? $b`. Null-ness is compile-time: a null-typed left yields
     * the fallback; a non-null scalar yields the left; a ptr-flavored
     * (string/obj/unknown) left gets a runtime `!= null` check.
     */
    /**
     * Closure literal → a heap struct of captured values (i64 each).
     * The closure value is the struct ptr; the fn itself is the
     * top-level `__closure_N` synthesised at lowering.
     */
    private function emitClosure(Node $n): string
    {
        $cl = $this->castClosure($n);
        $cnt = \count($cl->captures);
        // Layout: [fn_ptr, cap0, cap1, ...]. The fn ptr at slot 0 lets a
        // closure invoked through a `Closure`-typed value (returned /
        // passed) dispatch indirectly; captures follow at slot 1+.
        $sz = 8 * (1 + $cnt);
        $buf = $this->allocSsa();
        $out = '  ' . $buf . ' = call ptr @__mir_alloc(i64 ' . (string)$sz . ")\n";
        $fnName = '__closure_' . (string)$cl->id;
        $fp = $this->allocSsa();
        $out .= '  ' . $fp . ' = ptrtoint ptr @manticore_' . $this->mangle($fnName) . " to i64\n";
        $out .= '  store i64 ' . $fp . ', ptr ' . $buf . "\n";
        $i = 0;
        foreach ($cl->captures as $c) {
            $byRef = ($cl->captureByRef[$i] ?? false) && $c->kind === Node::KIND_LOAD_LOCAL;
            if ($byRef) {
                // `use (&$x)`: pack the ADDRESS of $x's slot so the closure
                // body (a byRef param → refLocal) reads/writes the original.
                // Already-ref enclosing locals hold the address; plain locals
                // take the slot address. No rc retain on a raw address.
                $name = $this->castLoadLocal($c)->name;
                $capV = $this->allocSsa();
                if (isset($this->refLocals[$name])) {
                    $out .= '  ' . $capV . ' = load i64, ptr ' . $this->slots[$name] . "\n";
                } else {
                    $out .= '  ' . $capV . ' = ptrtoint ptr ' . $this->slots[$name] . " to i64\n";
                }
            } else {
                $out .= $this->emitNode($c);
                $out .= $this->coerceToI64();
                $capV = $this->lastValue;
                // The closure owns a reference to each captured obj.
                $out .= $this->rcRetainByType($c, $capV, null, 1);
            }
            $gep = $this->allocSsa();
            $out .= '  ' . $gep . ' = getelementptr inbounds i64, ptr ' . $buf . ', i64 ' . (string)($i + 1) . "\n";
            $out .= '  store i64 ' . $capV . ', ptr ' . $gep . "\n";
            $i = $i + 1;
        }
        $this->lastValue = $buf;
        $this->lastValueType = 'ptr';
        return $out;
    }

    /** A concrete scalar param the uniform closure ABI passes as a cell — the
     *  caller boxes it, the closure entry unboxes it. Excludes cell (already
     *  tagged) and array/obj/closure (passed raw; boxToCell would rebuild). */
    private function isCellScalarParam(Type $t): bool
    {
        $k = $t->kind;
        return $k === Type::KIND_INT || $k === Type::KIND_FLOAT
            || $k === Type::KIND_BOOL || $k === Type::KIND_STRING;
    }

    /** A value the uniform closure ABI boxes into a cell at the call site (and
     *  at a scalar return). Includes cell (no-op box). Arrays/objects/closures
     *  travel raw — their masked heap ptr is identity, and boxToCell would
     *  rebuild an array's elements. */
    private function isCellBoxableArg(Type $t): bool
    {
        $k = $t->kind;
        return $k === Type::KIND_INT || $k === Type::KIND_FLOAT
            || $k === Type::KIND_BOOL || $k === Type::KIND_STRING
            || $k === Type::KIND_NULL || $k === Type::KIND_CELL;
    }

    /** Box the current lastValue into a tagged cell chosen by its LLVM repr
     *  (double → box_float, ptr → box_ptr, else box_int). Used for an
     *  unknown-typed value whose static type can't pick the box but whose
     *  carrier repr can. lastValue ← the boxed i64. */
    private function boxLastByRepr(): string
    {
        if ($this->lastValueType === 'double') {
            $r = $this->allocSsa();
            $out = '  ' . $r . ' = call i64 @__manticore_box_float(double ' . $this->lastValue . ")\n";
            $this->lastValue = $r;
            $this->lastValueType = 'i64';
            return $out;
        }
        if ($this->lastValueType === 'ptr') {
            $r = $this->allocSsa();
            $out = '  ' . $r . ' = call i64 @__manticore_box_ptr(ptr ' . $this->lastValue . ")\n";
            $this->lastValue = $r;
            $this->lastValueType = 'i64';
            return $out;
        }
        $out = $this->coerceToI64();
        $r = $this->allocSsa();
        $out .= '  ' . $r . ' = call i64 @__manticore_box_int(i64 ' . $this->lastValue . ")\n";
        $this->lastValue = $r;
        $this->lastValueType = 'i64';
        return $out;
    }

    /** `$closure(args)` → load captures from the struct, call __closure_N. */
    private function emitInvoke(Node $n): string
    {
        $iv = $this->castInvoke($n);
        $fn = $iv->callee->type->class ?? '';
        // An invokable object: `$obj(...)` on a real class with __invoke
        // reroutes to `$obj->__invoke(...)` (closures keep the struct path).
        if ($fn !== '' && isset($this->classes[$fn])
            && $this->resolveMethodClass($fn, '__invoke') !== '') {
            $call = new \Compile\Mir\MethodCall_($iv->callee, '__invoke', $iv->args, $n->type);
            return $this->emitMethodCall($call);
        }
        // The closure struct is the env: the __closure fn unpacks its own
        // captures from it (slot 1+), so the call passes only `env + args`.
        $out = $this->emitNode($iv->callee);
        $out .= $this->coerceToPtr();
        $struct = $this->lastValue;
        $argList = 'ptr ' . $struct;
        $argTypes = 'ptr';
        // Uniform closure ABI: box each scalar arg into a tagged cell so the
        // call works whether the callee is known or a dynamic `callable` (the
        // closure entry unboxes a concrete-scalar param). Arrays/objects pass
        // raw (boxToCell would rebuild an array). Without this a cell-typed
        // param reads the raw arg bits → a string renders as its pointer.
        foreach ($iv->args as $a) {
            $out .= $this->emitNode($a);
            if ($this->isCellBoxableArg($a->type)) {
                $out .= $this->boxToCell($a->type);
            } else {
                $out .= $this->coerceToI64();
            }
            $argList .= ', i64 ' . $this->lastValue;
            $argTypes .= ', i64';
        }
        $known = $fn !== '' && isset($this->closureCaptures[$fn]);
        $reg = $this->allocSsa();
        if ($known) {
            $out .= '  ' . $reg . ' = call i64 @manticore_' . $this->mangle($fn) . '(' . $argList . ")\n";
        } else {
            // Dynamic dispatch: load the fn ptr from struct slot 0 and call
            // indirectly (the callee is a `Closure`-typed value whose
            // concrete __closure_N isn't known statically).
            $fpi = $this->allocSsa();
            $out .= '  ' . $fpi . ' = load i64, ptr ' . $struct . "\n";
            $fp = $this->allocSsa();
            $out .= '  ' . $fp . ' = inttoptr i64 ' . $fpi . " to ptr\n";
            $out .= '  ' . $reg . ' = call i64 (' . $argTypes . ') ' . $fp . '(' . $argList . ")\n";
        }
        $this->lastValue = $reg;
        $this->lastValueType = 'i64';
        // The closure returned a scalar as a tagged cell (uniform ABI). Unbox
        // to the invoke's static type — a known closure types it from the sig
        // (string/int/float/…); a dynamic one is cell ({@see inferInvoke}) and
        // stays boxed. A non-scalar (array/obj) result rode raw → no unbox.
        if ($this->isCellScalarParam($n->type)) {
            $out .= $this->unboxCellToType($n->type);
        }
        return $out;
    }

    private function emitNullCoalesce(Node $n): string
    {
        $nc = $this->castNullCoalesce($n);
        if ($nc->left->kind === Node::KIND_PROPERTY_ACCESS) {
            $lpa = $this->castPropertyAccess($nc->left);
            if ($lpa->object->kind === Node::KIND_PROPERTY_ACCESS
                && $lpa->object->type->kind === Type::KIND_OBJ) {
                return $this->emitCoalesceChain($nc, $n->type);
            }
        }
        // An array/assoc access can be ABSENT (PHP: missing key/index →
        // null → use the default). Decide by key/index PRESENCE, not by
        // the value: the int short-circuit below would otherwise drop the
        // default entirely, and an int-valued assoc reads a missing key as
        // 0 — indistinguishable from a present 0 by a value-null check.
        if ($nc->left->kind === Node::KIND_ARRAY_ACCESS) {
            // A cell result (a `mixed`-element array — e.g. ArrayAccess offsetGet)
            // must store BOTH arms as cells: the left rides a cell already (the
            // cell-element read), the right (a raw scalar default) is boxed, so a
            // downstream `mixed` return / var_dump reads a uniform tagged value
            // instead of re-boxing the cell arm (the double-box masked by the
            // 48-bit truncation).
            $wantCell = $n->type->kind === Type::KIND_CELL;
            $res = $this->allocSsa();
            $out = '  ' . $res . " = alloca i64\n";
            $out .= $this->emitIssetTarget($nc->left);
            $present = $this->lastValue;
            $bit = $this->allocSsa();
            $out .= '  ' . $bit . ' = icmp ne i64 ' . $present . ", 0\n";
            $useL = $this->allocLabel('nc.left');
            $useR = $this->allocLabel('nc.right');
            $end  = $this->allocLabel('nc.end');
            $out .= '  br i1 ' . $bit . ', label %' . $useL . ', label %' . $useR . "\n";
            $out .= $useL . ":\n";
            $out .= $this->emitNode($nc->left);
            $out .= $wantCell ? $this->boxToCell($nc->left->type) : $this->coerceToI64();
            $out .= '  store i64 ' . $this->lastValue . ', ptr ' . $res . "\n";
            $out .= '  br label %' . $end . "\n";
            $out .= $useR . ":\n";
            $out .= $this->emitNode($nc->right);
            $out .= $wantCell ? $this->boxToCell($nc->right->type) : $this->coerceToI64();
            $out .= '  store i64 ' . $this->lastValue . ', ptr ' . $res . "\n";
            $out .= '  br label %' . $end . "\n";
            $out .= $end . ":\n";
            $loaded = $this->allocSsa();
            $out .= '  ' . $loaded . ' = load i64, ptr ' . $res . "\n";
            $this->lastValue = $loaded;
            $this->lastValueType = 'i64';
            return $out;
        }
        $lk = $nc->left->type->kind;
        if ($lk === Type::KIND_NULL) {
            return $this->emitNode($nc->right);
        }
        if ($lk === Type::KIND_INT || $lk === Type::KIND_FLOAT || $lk === Type::KIND_BOOL) {
            return $this->emitNode($nc->left);
        }
        // Runtime: use left when it isn't null. A null POINTER is 0
        // (string/obj/array), a null SCALAR is the boxed-NULL sentinel (a
        // nullable `?int`/`?float`/`?bool` rides a numeric cell) — reject both.
        // The raw int/float/bool cases returned above, so box_null can't collide.
        $res = $this->allocSsa();
        $out = '  ' . $res . " = alloca i64\n";
        $out .= $this->emitNode($nc->left);
        $out .= $this->coerceToI64();
        $lv = $this->lastValue;
        $nz = $this->allocSsa();
        $out .= '  ' . $nz . ' = icmp ne i64 ' . $lv . ", 0\n";
        $nnul = $this->allocSsa();
        $out .= '  ' . $nnul . ' = icmp ne i64 ' . $lv . ", -3659174697238528\n";
        $bit = $this->allocSsa();
        $out .= '  ' . $bit . ' = and i1 ' . $nz . ', ' . $nnul . "\n";
        // A cell result (arms of differing repr) boxes BOTH arms so a consumer
        // (echo / var_dump) dispatches on the arm actually taken.
        $wantCell = $n->type->kind === Type::KIND_CELL;
        $useL = $this->allocLabel('nc.left');
        $useR = $this->allocLabel('nc.right');
        $end  = $this->allocLabel('nc.end');
        $out .= '  br i1 ' . $bit . ', label %' . $useL . ', label %' . $useR . "\n";
        $out .= $useL . ":\n";
        if ($wantCell) {
            $this->lastValue = $lv;
            $this->lastValueType = 'i64';
            $out .= $this->boxToCell($nc->left->type);
            $out .= '  store i64 ' . $this->lastValue . ', ptr ' . $res . "\n";
        } else {
            $out .= '  store i64 ' . $lv . ', ptr ' . $res . "\n";
        }
        $out .= '  br label %' . $end . "\n";
        $out .= $useR . ":\n";
        $out .= $this->emitNode($nc->right);
        $out .= $wantCell ? $this->boxToCell($nc->right->type) : $this->coerceToI64();
        $out .= '  store i64 ' . $this->lastValue . ', ptr ' . $res . "\n";
        $out .= '  br label %' . $end . "\n";
        $out .= $end . ":\n";
        $loaded = $this->allocSsa();
        $out .= '  ' . $loaded . ' = load i64, ptr ' . $res . "\n";
        $this->lastValue = $loaded;
        $this->lastValueType = 'i64';
        return $out;
    }

    private function emitCoalesceChain(NullCoalesce_ $nc, Type $resultType): string
    {
        $chain = [];
        $node = $nc->left;
        while ($node->kind === Node::KIND_PROPERTY_ACCESS) {
            $pa = $this->castPropertyAccess($node);
            $rc = $pa->object->type->class ?? '';
            if ($pa->object->type->kind === Type::KIND_OBJ && $rc !== ''
                && isset($this->classes[$rc]) && !isset($this->enums[$rc])
                && !$this->classes[$rc]->usesBag()
                && $this->classes[$rc]->propertyOffset($pa->property) >= 0
                && !isset($this->classes[$rc]->propHooks[$pa->property])) {
                $chain[] = $pa;
                $node = $pa->object;
            } else {
                break;
            }
        }
        $hops = \count($chain);
        $leafPa = $this->castPropertyAccess($chain[0]);
        $leafCls = $leafPa->object->type->class ?? '';
        $leafType = ($leafCls !== '' && isset($this->classes[$leafCls]))
            ? ($this->classes[$leafCls]->propertyTypes[$leafPa->property] ?? Type::unknown())
            : Type::unknown();
        $wantCell = $resultType->kind === Type::KIND_CELL;
        $res = $this->allocSsa();
        $out = '  ' . $res . " = alloca i64\n";
        $useR = $this->allocLabel('ncc.right');
        $keep = $this->allocLabel('ncc.keep');
        $end  = $this->allocLabel('ncc.end');
        $out .= $this->emitNode($node);
        $out .= $this->coerceToI64();
        $cur = $this->lastValue;
        for ($i = $hops - 1; $i >= 0; $i = $i - 1) {
            $hop = $this->castPropertyAccess($chain[$i]);
            $hoff = $this->propertyOffset($hop->object, $hop->property);
            $z0 = $this->allocSsa();
            $out .= '  ' . $z0 . ' = icmp eq i64 ' . $cur . ", 0\n";
            $cont = $this->allocLabel('ncc.hop');
            $out .= '  br i1 ' . $z0 . ', label %' . $useR . ', label %' . $cont . "\n";
            $out .= $cont . ":\n";
            $op = $this->allocSsa();
            $out .= '  ' . $op . ' = inttoptr i64 ' . $cur . " to ptr\n";
            $fp = $this->allocSsa();
            $out .= '  ' . $fp . ' = getelementptr inbounds i8, ptr ' . $op . ', i64 ' . (string)$hoff . "\n";
            $nx = $this->allocSsa();
            $out .= '  ' . $nx . ' = load i64, ptr ' . $fp . "\n";
            $cur = $nx;
        }
        // A present-but-NULL leaf value also takes the default.
        $vn = $this->allocSsa();
        $out .= '  ' . $vn . ' = icmp eq i64 ' . $cur . ", -3659174697238528\n";
        $out .= '  br i1 ' . $vn . ', label %' . $useR . ', label %' . $keep . "\n" . $keep . ":\n";
        if ($wantCell) {
            $out .= $this->boxRawValue($cur, $leafType);
            $out .= '  store i64 ' . $this->lastValue . ', ptr ' . $res . "\n";
        } else {
            $out .= '  store i64 ' . $cur . ', ptr ' . $res . "\n";
        }
        $out .= '  br label %' . $end . "\n";
        $out .= $useR . ":\n";
        $out .= $this->emitNode($nc->right);
        $out .= $wantCell ? $this->boxToCell($nc->right->type) : $this->coerceToI64();
        $out .= '  store i64 ' . $this->lastValue . ', ptr ' . $res . "\n";
        $out .= '  br label %' . $end . "\n";
        $out .= $end . ":\n";
        $loaded = $this->allocSsa();
        $out .= '  ' . $loaded . ' = load i64, ptr ' . $res . "\n";
        $this->lastValue = $loaded;
        $this->lastValueType = 'i64';
        return $out;
    }

    /**
     * Load an object's class_id THROUGH its header-slot-0 descriptor pointer
     * (`{ i64 class_id, ptr drop_fn }`). Leaves the id reg in
     * {@see $classIdReg} and returns the IR. Used by instanceof / virtual
     * dispatch / exception catch, which match against compile-time id sets.
     */
    /** Out-param reg for {@see emitClassIdMatch}. */
    private string $classIdMatchReg = '';

    /**
     * OR-chain of `class_id == id` over $ids; returns the IR, leaves the
     * final i1 reg in {@see $classIdMatchReg}.
     * @param int[] $ids
     */
    private function emitClassIdMatch(string $cid, array $ids): string
    {
        $out = '';
        $acc = '';
        foreach ($ids as $id) {
            $m = $this->allocSsa();
            $out .= '  ' . $m . ' = icmp eq i64 ' . $cid . ', ' . (string)$id . "\n";
            if ($acc === '') {
                $acc = $m;
            } else {
                $or = $this->allocSsa();
                $out .= '  ' . $or . ' = or i1 ' . $acc . ', ' . $m . "\n";
                $acc = $or;
            }
        }
        $this->classIdMatchReg = $acc;
        return $out;
    }

    private function emitLoadClassId(string $objpReg): string
    {
        $descI = $this->allocSsa();
        $ir = '  ' . $descI . ' = load i64, ptr ' . $objpReg . "\n";
        $descP = $this->allocSsa();
        $ir .= '  ' . $descP . ' = inttoptr i64 ' . $descI . " to ptr\n";
        $cid = $this->allocSsa();
        $ir .= '  ' . $cid . ' = load i64, ptr ' . $descP . "\n";
        $this->classIdReg = $cid;
        return $ir;
    }

    private function emitInstanceof(Node $n): string
    {
        $io = $this->castInstanceof($n);
        $out = $this->emitNode($io->operand);
        $out .= $this->coerceToI64();
        $obj = $this->lastValue;
        $ids = $this->instanceofMatchIds($io->class);
        if ($ids === []) {
            $this->lastValue = '0';
            $this->lastValueType = 'i64';
            return $out;
        }
        // A cell operand is an instance only when it's object-tagged (tag 8):
        // any other tag (int/string/null/array/...) → false. Guard the
        // class_id load behind the tag check (a non-object cell's payload is
        // not an object ptr) and unbox the payload before reading the id. A
        // result slot avoids a phi.
        if ($io->operand->type->kind === Type::KIND_CELL) {
            $slot = $this->allocSsa();
            $out .= '  ' . $slot . " = alloca i64\n";
            $out .= '  store i64 0, ptr ' . $slot . "\n";
            $out .= $this->cellTagIr($obj);
            $tag = $this->cellTagReg;
            $isObj = $this->allocSsa();
            $out .= '  ' . $isObj . ' = icmp eq i64 ' . $tag . ", 8\n";
            $objL = $this->allocLabel('io.obj');
            $doneL = $this->allocLabel('io.done');
            $out .= '  br i1 ' . $isObj . ', label %' . $objL . ', label %' . $doneL . "\n";
            $out .= $objL . ":\n";
            $payload = $this->allocSsa();
            $out .= '  ' . $payload . ' = and i64 ' . $obj . ", 281474976710655\n";
            $objpc = $this->allocSsa();
            $out .= '  ' . $objpc . ' = inttoptr i64 ' . $payload . " to ptr\n";
            $out .= $this->emitLoadClassId($objpc);
            $out .= $this->emitClassIdMatch($this->classIdReg, $ids);
            $accc = $this->classIdMatchReg;
            $mext = $this->allocSsa();
            $out .= '  ' . $mext . ' = zext i1 ' . $accc . " to i64\n";
            $out .= '  store i64 ' . $mext . ', ptr ' . $slot . "\n";
            $out .= '  br label %' . $doneL . "\n";
            $out .= $doneL . ":\n";
            $reg = $this->allocSsa();
            $out .= '  ' . $reg . ' = load i64, ptr ' . $slot . "\n";
            $this->lastValue = $reg;
            $this->lastValueType = 'i64';
            return $out;
        }
        // A non-cell obj operand can be a null (0) pointer at runtime — an
        // obj-typed value from a plain-ternary null arm (`$c ? new P() : null`).
        // Reading the class id from a null ptr is a wild load (heap roulette
        // SIGSEGV); guard it — null is an instance of nothing. A result slot
        // avoids a phi (mirrors the cell path above).
        $slot = $this->allocSsa();
        $out .= '  ' . $slot . " = alloca i64\n";
        $out .= '  store i64 0, ptr ' . $slot . "\n";
        $isNull = $this->allocSsa();
        $out .= '  ' . $isNull . ' = icmp eq i64 ' . $obj . ", 0\n";
        $objL = $this->allocLabel('io.obj');
        $doneL = $this->allocLabel('io.done');
        $out .= '  br i1 ' . $isNull . ', label %' . $doneL . ', label %' . $objL . "\n";
        $out .= $objL . ":\n";
        $objp = $this->allocSsa();
        $out .= '  ' . $objp . ' = inttoptr i64 ' . $obj . " to ptr\n";
        $out .= $this->emitLoadClassId($objp);
        $out .= $this->emitClassIdMatch($this->classIdReg, $ids);
        $mx = $this->allocSsa();
        $out .= '  ' . $mx . ' = zext i1 ' . $this->classIdMatchReg . " to i64\n";
        $out .= '  store i64 ' . $mx . ', ptr ' . $slot . "\n";
        $out .= '  br label %' . $doneL . "\n";
        $out .= $doneL . ":\n";
        $reg = $this->allocSsa();
        $out .= '  ' . $reg . ' = load i64, ptr ' . $slot . "\n";
        $this->lastValue = $reg;
        $this->lastValueType = 'i64';
        return $out;
    }

    /**
     * class_ids of every class that is-a `$target` — `$target` itself
     * plus descendants (class match), classes implementing it
     * (interface match via the ancestor chain), or — for `Stringable`
     * — any class with a `__toString`.
     *
     * @return int[]
     */
    private function instanceofMatchIds(string $target): array
    {
        $ids = [];
        foreach ($this->classes as $name => $cd) {
            if ($this->classIsA($name, $target)) { $ids[] = $cd->classId; }
        }
        return $ids;
    }

    private function classIsA(string $name, string $target): bool
    {
        if ($target === 'Stringable') {
            return $this->resolveMethodClass($name, '__toString') !== '';
        }
        $c = $name;
        while ($c !== '') {
            $cd = $this->classes[$c] ?? null;
            if ($cd === null) { return false; }
            if ($c === $target) { return true; }
            if (\in_array($target, $cd->interfaces, true)) { return true; }
            $c = $cd->parent;
        }
        return false;
    }

    private function emitCast(Node $n): string
    {
        $c = $this->castCast($n);
        $ok = $c->operand->type->kind;
        $out = $this->emitNode($c->operand);
        if ($c->target === 'string') {
            if ($ok === Type::KIND_STRING) { $out .= $this->coerceToPtr(); return $out; }
            if ($ok === Type::KIND_CELL) {
                $this->needsTaggedToStr = true;
                $out .= $this->coerceToI64();
                $r = $this->allocSsa();
                $out .= '  ' . $r . ' = call ptr @__manticore_tagged_to_str(i64 ' . $this->lastValue . ")\n";
                $this->lastValue = $r; $this->lastValueType = 'ptr';
                return $out;
            }
            $out .= $this->coerceToStr($c->operand);
            return $out;
        }
        if ($c->target === 'int') {
            if ($ok === Type::KIND_STRING) {
                $this->needsStrtol = true;
                $out .= $this->coerceToPtr();
                $reg = $this->allocSsa();
                $out .= '  ' . $reg . ' = call i64 @strtol(ptr ' . $this->lastValue . ', ptr null, i32 10)' . "\n";
                $this->lastValue = $reg; $this->lastValueType = 'i64';
                return $out;
            }
            if ($ok === Type::KIND_FLOAT) {
                $out .= $this->coerceTo('double');
                $reg = $this->allocSsa();
                $out .= '  ' . $reg . ' = fptosi double ' . $this->lastValue . " to i64\n";
                $this->lastValue = $reg; $this->lastValueType = 'i64';
                return $out;
            }
            if ($ok === Type::KIND_CELL) {
                $this->needsTaggedToInt = true;
                $this->needsStrtol = true;
                $out .= $this->coerceToI64();
                $reg = $this->allocSsa();
                $out .= '  ' . $reg . ' = call i64 @__manticore_tagged_to_int(i64 ' . $this->lastValue . ")\n";
                $this->lastValue = $reg; $this->lastValueType = 'i64';
                return $out;
            }
            $out .= $this->coerceToI64();
            return $out;
        }
        if ($c->target === 'float') {
            if ($ok === Type::KIND_STRING) {
                $this->needsStrtod = true;
                $out .= $this->coerceToPtr();
                $reg = $this->allocSsa();
                $out .= '  ' . $reg . ' = call double @strtod(ptr ' . $this->lastValue . ', ptr null)' . "\n";
                $this->lastValue = $reg; $this->lastValueType = 'double';
                return $out;
            }
            if ($ok === Type::KIND_FLOAT) { $out .= $this->coerceTo('double'); return $out; }
            if ($ok === Type::KIND_CELL) {
                $this->needsTaggedToFloat = true;
                $out .= $this->coerceToI64();
                $reg = $this->allocSsa();
                $out .= '  ' . $reg . ' = call double @__manticore_tagged_to_double(i64 ' . $this->lastValue . ")\n";
                $this->lastValue = $reg; $this->lastValueType = 'double';
                return $out;
            }
            $out .= $this->coerceToI64();
            $reg = $this->allocSsa();
            $out .= '  ' . $reg . ' = sitofp i64 ' . $this->lastValue . " to double\n";
            $this->lastValue = $reg; $this->lastValueType = 'double';
            return $out;
        }
        if ($c->target === 'object') {
            // `(object)$assoc` → a stdClass whose bag is that assoc.
            $std = $this->classes['stdClass'] ?? null;
            $bagOff = $std === null ? 16 : $std->bagOffset();
            $size = $std === null ? 24 : $std->instanceSize();
            $out .= $this->coerceToPtr();
            $bagI = $this->allocSsa();
            $out .= '  ' . $bagI . ' = ptrtoint ptr ' . $this->lastValue . " to i64\n";
            $obj = $this->allocSsa();
            $out .= '  ' . $obj . ' = call ptr @__mir_alloc_tagged(i64 ' . (string)$size . ")\n";
            $out .= '  store i64 ' . $this->descSlotValue($std) . ', ptr ' . $obj . "\n";
            $rcg = $this->allocSsa();
            $out .= '  ' . $rcg . ' = getelementptr inbounds i64, ptr ' . $obj . ", i64 1\n";
            $out .= '  store i64 1, ptr ' . $rcg . "\n";
            $bg = $this->allocSsa();
            $out .= '  ' . $bg . ' = getelementptr inbounds i8, ptr ' . $obj . ', i64 ' . (string)$bagOff . "\n";
            $out .= '  store i64 ' . $bagI . ', ptr ' . $bg . "\n";
            $this->lastValue = $obj; $this->lastValueType = 'ptr';
            return $out;
        }
        if ($c->target === 'array') {
            // `(array)$cell` — a tagged OBJECT cell → its bag assoc.
            if ($ok === Type::KIND_CELL) {
                $out .= $this->cellToPtr();
                $std = $this->classes['stdClass'] ?? null;
                $bagOff = $std === null ? 16 : $std->bagOffset();
                $bg = $this->allocSsa();
                $out .= '  ' . $bg . ' = getelementptr inbounds i8, ptr ' . $this->lastValue . ', i64 ' . (string)$bagOff . "\n";
                $bagI = $this->allocSsa();
                $out .= '  ' . $bagI . ' = load i64, ptr ' . $bg . "\n";
                $bagP = $this->allocSsa();
                $out .= '  ' . $bagP . ' = inttoptr i64 ' . $bagI . " to ptr\n";
                $this->lastValue = $bagP; $this->lastValueType = 'ptr';
                return $out;
            }
            // `(array)$stdClass` → its bag assoc; an array stays itself.
            if ($ok === Type::KIND_OBJ) {
                $std = $this->classes['stdClass'] ?? null;
                $bagOff = $std === null ? 16 : $std->bagOffset();
                $out .= $this->coerceToPtr();
                $bg = $this->allocSsa();
                $out .= '  ' . $bg . ' = getelementptr inbounds i8, ptr ' . $this->lastValue . ', i64 ' . (string)$bagOff . "\n";
                $bagI = $this->allocSsa();
                $out .= '  ' . $bagI . ' = load i64, ptr ' . $bg . "\n";
                $bagP = $this->allocSsa();
                $out .= '  ' . $bagP . ' = inttoptr i64 ' . $bagI . " to ptr\n";
                $this->lastValue = $bagP; $this->lastValueType = 'ptr';
                return $out;
            }
            $out .= $this->coerceToPtr();
            return $out;
        }
        // bool: truthiness → i64 0/1. A cell must unbox by tag (a boxed
        // 0/false/"" has non-zero raw bits → would read truthy).
        if ($ok === Type::KIND_CELL) {
            $this->needsTaggedTruthy = true;
            $out .= $this->coerceToI64();
            $r = $this->allocSsa();
            $out .= '  ' . $r . ' = call i64 @__manticore_tagged_truthy(i64 ' . $this->lastValue . ")\n";
            $this->lastValue = $r; $this->lastValueType = 'i64';
            return $out;
        }
        $out .= $this->coerceToI64();
        $bit = $this->allocSsa();
        $out .= '  ' . $bit . ' = icmp ne i64 ' . $this->lastValue . ", 0\n";
        $reg = $this->allocSsa();
        $out .= '  ' . $reg . ' = zext i1 ' . $bit . " to i64\n";
        $this->lastValue = $reg; $this->lastValueType = 'i64';
        return $out;
    }

    private function emitIncDec(Node $n): string
    {
        $d = $this->castIncDec($n);
        $instr = $d->op === '+' ? 'add' : 'sub';
        // Static locals (backed by a global cell) and by-ref params / captures
        // (the slot holds a POINTER to the real storage) don't live in a plain
        // i64 slot. `++`/`--` must load/store through the same indirection as
        // Load/StoreLocal, else the write-back hits a stale local and no-ops.
        if (isset($this->globalBackedLocals[$d->name])) {
            $cell = $this->globalBackedLocals[$d->name];
            $old = $this->allocSsa();
            $out = '  ' . $old . ' = load i64, ptr ' . $cell . "\n";
            $new = $this->allocSsa();
            $out .= '  ' . $new . ' = ' . $instr . ' i64 ' . $old . ", 1\n";
            $out .= '  store i64 ' . $new . ', ptr ' . $cell . "\n";
            $this->lastValue = $d->prefix ? $new : $old;
            $this->lastValueType = 'i64';
            return $out;
        }
        if (isset($this->refLocals[$d->name]) && isset($this->slots[$d->name])) {
            $addr = $this->allocSsa();
            $out = '  ' . $addr . ' = load i64, ptr ' . $this->slots[$d->name] . "\n";
            $p = $this->allocSsa();
            $out .= '  ' . $p . ' = inttoptr i64 ' . $addr . " to ptr\n";
            $old = $this->allocSsa();
            $out .= '  ' . $old . ' = load i64, ptr ' . $p . "\n";
            $new = $this->allocSsa();
            $out .= '  ' . $new . ' = ' . $instr . ' i64 ' . $old . ", 1\n";
            $out .= '  store i64 ' . $new . ', ptr ' . $p . "\n";
            $this->lastValue = $d->prefix ? $new : $old;
            $this->lastValueType = 'i64';
            return $out;
        }
        $slot = $this->slots[$d->name] ?? null;
        if ($slot === null) {
            // No prior assignment seen — treat as starting from 0.
            $slot = $this->allocSsa();
            $this->slots[$d->name] = $slot;
            $out = '  ' . $slot . " = alloca i64\n";
            $out .= '  store i64 0, ptr ' . $slot . "\n";
        } else {
            $out = '';
        }
        $old = $this->allocSsa();
        $out .= '  ' . $old . ' = load i64, ptr ' . $slot . "\n";
        $new = $this->allocSsa();
        $out .= '  ' . $new . ' = ' . $instr . ' i64 ' . $old . ", 1\n";
        $out .= '  store i64 ' . $new . ', ptr ' . $slot . "\n";
        $this->lastValue = $d->prefix ? $new : $old;
        $this->lastValueType = 'i64';
        return $out;
    }

    private function emitTernary(Node $n): string
    {
        $t = $this->castTernary($n);
        $res = $this->allocSsa();
        $out = '  ' . $res . " = alloca i64\n";
        // Short ternary (`?:`) reuses the operand as its then-value, so keep its
        // RAW value and compute truthiness separately — else a string/cell operand
        // whose truthiness is a computed 0/1 (not the raw carrier) would return
        // that 0/1 as the value (a `1` used as a string ptr → SIGSEGV).
        $rawCond = '0';
        if ($t->then === null) {
            $out .= $this->emitNode($t->cond);
            $out .= $this->coerceToI64();
            $rawCond = $this->lastValue;
            $out .= $this->truthinessOf($t->cond->type);
            $cond = $this->lastValue;
        } else {
            $out .= $this->emitCondVal($t->cond);
            $cond = $this->lastValue;
        }
        $thenLabel = $this->allocLabel('tern.then');
        $elseLabel = $this->allocLabel('tern.else');
        $endLabel  = $this->allocLabel('tern.end');
        $condBit = $this->allocSsa();
        $out .= '  ' . $condBit . ' = icmp ne i64 ' . $cond . ", 0\n";
        $out .= '  br i1 ' . $condBit . ', label %' . $thenLabel . ', label %' . $elseLabel . "\n";
        // When the result type is a cell (heterogeneous branches, see
        // inferTernary), each branch must be BOXED so both store a uniform
        // tagged value; otherwise coerceToI64 stores a raw array/int next to
        // a boxed cell and the consumer mis-reads it. boxToCell no-ops a value
        // that is already a cell, so no double-boxing.
        $wantCell = $n->type->kind === Type::KIND_CELL;
        // then: short ternary (`?:`) reuses the condition value.
        $out .= $thenLabel . ":\n";
        if ($t->then !== null) {
            $out .= $this->emitNode($t->then);
            $out .= $wantCell ? $this->boxToCell($t->then->type) : $this->coerceToI64();
            $thenVal = $this->lastValue;
        } elseif ($wantCell) {
            $this->lastValue = $rawCond;
            $this->lastValueType = 'i64';
            $out .= $this->boxToCell($t->cond->type);
            $thenVal = $this->lastValue;
        } else {
            $thenVal = $rawCond;
        }
        $out .= '  store i64 ' . $thenVal . ', ptr ' . $res . "\n";
        $out .= '  br label %' . $endLabel . "\n";
        $out .= $elseLabel . ":\n";
        $out .= $this->emitNode($t->else_);
        $out .= $wantCell ? $this->boxToCell($t->else_->type) : $this->coerceToI64();
        $out .= '  store i64 ' . $this->lastValue . ', ptr ' . $res . "\n";
        $out .= '  br label %' . $endLabel . "\n";
        $out .= $endLabel . ":\n";
        $loaded = $this->allocSsa();
        $out .= '  ' . $loaded . ' = load i64, ptr ' . $res . "\n";
        $this->lastValue = $loaded;
        $this->lastValueType = 'i64';
        if ($n->type->kind === Type::KIND_FLOAT) {
            $regF = $this->allocSsa();
            $out .= '  ' . $regF . ' = bitcast i64 ' . $loaded . " to double\n";
            $this->lastValue = $regF;
            $this->lastValueType = 'double';
        }
        return $out;
    }

    /**
     * `foreach ($arr as [$k =>] $v)` over a vec (int keys) or assoc
     * (string keys). i64 index loop; element/value copied into the
     * value-var slot each iteration (key into the key-var slot). `&$v`
     * writes the slot back into the array element at the step block.
     * `break`/`continue` use the loop labels (continue → step, so a
     * by-ref writeback still runs).
     */
    /** Emit a GEP to generator frame slot `$idx`; sets lastValue to the ptr. */
    private function genFrameSlotPtr(int $idx): string
    {
        $p = $this->allocSsa();
        $this->lastValue = $p;
        $this->lastValueType = 'ptr';
        return '  ' . $p . ' = getelementptr inbounds i8, ptr %frame, i64 '
             . (string)(self::GEN_HEADER + 8 * $idx) . "\n";
    }

    /** Reload the frame-stored array ptr into a fresh SSA; sets lastValue. */
    private function genReloadArr(string $arrSlot): string
    {
        $ai = $this->allocSsa();
        $out = '  ' . $ai . ' = load i64, ptr ' . $arrSlot . "\n";
        $ap = $this->allocSsa();
        $out .= '  ' . $ap . ' = inttoptr i64 ' . $ai . " to ptr\n";
        $this->lastValue = $ap;
        $this->lastValueType = 'ptr';
        return $out;
    }

    private function emitForeach(Node $n): string
    {
        $fe = $this->castForeach($n);
        if ($this->isGeneratorType($fe->array->type)) {
            return $this->emitForeachGenerator($fe);
        }
        if ($this->isTraversableType($fe->array->type)) {
            return $this->emitForeachObject($fe);
        }
        $out = '';
        if (!isset($this->slots[$fe->valueVar])) {
            $vs = $this->allocSsa();
            $this->slots[$fe->valueVar] = $vs;
            $out .= '  ' . $vs . " = alloca i64\n";
        }
        if ($fe->keyVar !== null && !isset($this->slots[$fe->keyVar])) {
            $ks = $this->allocSsa();
            $this->slots[$fe->keyVar] = $ks;
            $out .= '  ' . $ks . " = alloca i64\n";
        }
        $out .= $this->emitNode($fe->array);
        // A `mixed` (cell) holding an array: strip the tag to the ptr.
        if ($fe->array->type->kind === Type::KIND_CELL) {
            $out .= $this->cellToPtr();
        } else {
            $out .= $this->coerceToPtr();
        }
        $arr = $this->lastValue;
        // Empty vec/assoc literals lower to a null ptr; reading the length
        // word from null faults. Redirect a null base to a shared zero word
        // so `len` reads 0 and the loop body is skipped entirely.
        $nz = $this->allocSsa();
        $out .= '  ' . $nz . ' = icmp eq ptr ' . $arr . ", null\n";
        $arrSafe = $this->allocSsa();
        $out .= '  ' . $arrSafe . ' = select i1 ' . $nz
              . ', ptr @__mir_zero_word, ptr ' . $arr . "\n";
        $arr = $arrSafe;

        // Inside a generator the iterator state (cursor + array ptr) must
        // survive a `yield` in the body, so it lives in two heap-frame slots
        // (the resume entry-switch re-enters mid-loop, killing any SSA / stack
        // alloca). $arr is then RELOADED from the frame in each block.
        $framed = $fe->genSlotBase >= 0;
        $arrSlot = '';
        if ($framed) {
            // Slot ptrs were computed in the resume entry block (dominate all
            // blocks, incl. the resume-switch targets) — use those, never a
            // mid-loop GEP that the resume edge would bypass.
            $iSlot = $this->slots["@fe.0." . (string)$fe->genSlotBase];
            $arrSlot = $this->slots["@fe.1." . (string)$fe->genSlotBase];
            $out .= '  store i64 0, ptr ' . $iSlot . "\n";
            $aint = $this->allocSsa();
            $out .= '  ' . $aint . ' = ptrtoint ptr ' . $arr . " to i64\n";
            $out .= '  store i64 ' . $aint . ', ptr ' . $arrSlot . "\n";
            $len = '0'; // recomputed in cond (reloaded array)
        } else {
            $iSlot = $this->allocSsa();
            $out .= '  ' . $iSlot . " = alloca i64\n";
            $out .= '  store i64 0, ptr ' . $iSlot . "\n";
            $len = $this->allocSsa();
            $out .= '  ' . $len . ' = load i64, ptr ' . $arr . "\n";
        }

        $condLabel = $this->allocLabel('fe.cond');
        $bodyLabel = $this->allocLabel('fe.body');
        $stepLabel = $this->allocLabel('fe.step');
        $endLabel  = $this->allocLabel('fe.end');
        $savedBreak = $this->breakLabel;
        $savedCont  = $this->continueLabel;
        $this->breakLabel = $endLabel;
        $this->continueLabel = $stepLabel;
        $this->breakStack[] = $endLabel;
        $this->continueStack[] = $stepLabel;

        // Per-iteration arena reset. Safe because the save point is taken
        // *after* the iterable + iterator state (`$arr`, `$iSlot`, `$len`)
        // are materialized, so a reset never frees the array being walked.
        // By-ref foreach writes the value slot back into the element, so an
        // arena value could escape into the (pre-save) array — skip it.
        $reset = !$fe->byRef && $this->loopArenaResettable(null, $fe->body, null);
        if ($reset) { $out .= $this->emitArenaSave(); }

        $out .= '  br label %' . $condLabel . "\n";
        $out .= $condLabel . ":\n";
        if ($reset) { $out .= $this->emitArenaReset(); }
        if ($framed) {
            $out .= $this->genReloadArr($arrSlot);
            $arr = $this->lastValue;
            $len = $this->allocSsa();
            $out .= '  ' . $len . ' = load i64, ptr ' . $arr . "\n";
        }
        $i = $this->allocSsa();
        $out .= '  ' . $i . ' = load i64, ptr ' . $iSlot . "\n";
        $c = $this->allocSsa();
        $out .= '  ' . $c . ' = icmp slt i64 ' . $i . ', ' . $len . "\n";
        $out .= '  br i1 ' . $c . ', label %' . $bodyLabel . ', label %' . $endLabel . "\n";

        $out .= $bodyLabel . ":\n";
        if ($framed) { $out .= $this->genReloadArr($arrSlot); $arr = $this->lastValue; }
        // element address + key
        $out .= $this->foreachElemAddrUnified($arr, $i);
        $valAddr = $this->feAddr;
        $valSlot = $this->slots[$fe->valueVar];
        $ev = $this->allocSsa();
        $out .= '  ' . $ev . ' = load i64, ptr ' . $valAddr . "\n";
        $out .= '  store i64 ' . $ev . ', ptr ' . $valSlot . "\n";
        if ($fe->keyVar !== null) {
            $kSlot = $this->slots[$fe->keyVar];
            // key_at handles packed (index) vs hashed (int / str ptr). Over a
            // `mixed`/cell, an erased/unknown, OR a cell-element array (which may
            // hold dynamic int-or-string keys) the key must come back NaN-boxed,
            // so route to the cell-boxing variant — matches the cell key type
            // InferTypes assigns there, so a downstream `$out[$k]=…` dispatches
            // by tag (set_cell).
            $kp = $this->allocSsa();
            $kk = $fe->array->type->kind;
            $elemK = $fe->array->type->element !== null ? $fe->array->type->element->kind : '';
            $keyK = $fe->array->type->key !== null ? $fe->array->type->key->kind : '';
            // Must mirror InferTypes::inferForeach's key-type decision exactly,
            // or a cell-typed key var would be read with the raw key_at (or vice
            // versa). Key is a tagged cell over: a cell/unknown source, a vec with
            // an erased (cell/unknown) element, or a cell-keyed assoc.
            $vecErased = $fe->array->type->isVec()
                && ($elemK === Type::KIND_CELL || $elemK === Type::KIND_UNKNOWN);
            if ($kk === Type::KIND_CELL || $kk === Type::KIND_UNKNOWN
                || $vecErased || $keyK === Type::KIND_CELL) {
                $out .= '  ' . $kp . ' = call i64 @__mir_array_key_cell_at(ptr ' . $arr . ', i64 ' . $i . ")\n";
            } else {
                $out .= '  ' . $kp . ' = call i64 @__mir_array_key_at(ptr ' . $arr . ', i64 ' . $i . ")\n";
            }
            $out .= '  store i64 ' . $kp . ', ptr ' . $kSlot . "\n";
        }
        $out .= $this->emitNode($fe->body);
        $out .= '  br label %' . $stepLabel . "\n";

        $out .= $stepLabel . ":\n";
        if ($framed && $fe->byRef) { $out .= $this->genReloadArr($arrSlot); $arr = $this->lastValue; }
        $si = $this->allocSsa();
        $out .= '  ' . $si . ' = load i64, ptr ' . $iSlot . "\n";
        if ($fe->byRef) {
            $out .= $this->foreachElemAddrUnified($arr, $si);
            $wAddr = $this->feAddr;
            $wv = $this->allocSsa();
            $out .= '  ' . $wv . ' = load i64, ptr ' . $this->slots[$fe->valueVar] . "\n";
            $out .= '  store i64 ' . $wv . ', ptr ' . $wAddr . "\n";
        }
        $si2 = $this->allocSsa();
        $out .= '  ' . $si2 . ' = add i64 ' . $si . ", 1\n";
        $out .= '  store i64 ' . $si2 . ', ptr ' . $iSlot . "\n";
        $out .= '  br label %' . $condLabel . "\n";
        $out .= $endLabel . ":\n";

        \array_pop($this->breakStack);
        \array_pop($this->continueStack);
        $this->breakLabel = $savedBreak;
        $this->continueLabel = $savedCont;
        return $out;
    }




    /**
     * Unified-array value address for foreach entry `$i` → $this->feAddr.
     * Selects at runtime between the PACKED slot (HEADER + i*8) and the
     * HASHED entry value field (HEADER + i*ENTRY + VALUE) on the flags
     * word. One address serves both the read and the `&$v` writeback
     * (in-place value overwrite — no grow, so no relocation).
     */
    private function foreachElemAddrUnified(string $arr, string $i): string
    {
        $H = (string)\Compile\MemoryAbi::ARRAY_HEADER_SIZE;
        $E = (string)\Compile\MemoryAbi::ARRAY_ENTRY_SIZE;
        $V = (string)\Compile\MemoryAbi::ARRAY_ENTRY_VALUE_OFFSET;
        $fo = (string)\Compile\MemoryAbi::ARRAY_FLAGS_OFFSET;
        $fa = $this->allocSsa();
        $out  = '  ' . $fa . ' = getelementptr inbounds i8, ptr ' . $arr . ', i64 ' . $fo . "\n";
        $fl = $this->allocSsa();
        $out .= '  ' . $fl . ' = load i64, ptr ' . $fa . "\n";
        $ish = $this->allocSsa();
        $out .= '  ' . $ish . ' = icmp ne i64 ' . $fl . ", 0\n";
        $po0 = $this->allocSsa();
        $out .= '  ' . $po0 . ' = mul i64 ' . $i . ', ' . (string)\Compile\MemoryAbi::ARRAY_PACKED_ELEMENT_SIZE . "\n";
        $po = $this->allocSsa();
        $out .= '  ' . $po . ' = add i64 ' . $po0 . ', ' . $H . "\n";
        $pa = $this->allocSsa();
        $out .= '  ' . $pa . ' = getelementptr inbounds i8, ptr ' . $arr . ', i64 ' . $po . "\n";
        $ho0 = $this->allocSsa();
        $out .= '  ' . $ho0 . ' = mul i64 ' . $i . ', ' . $E . "\n";
        $ho = $this->allocSsa();
        $out .= '  ' . $ho . ' = add i64 ' . $ho0 . ', ' . (string)(\Compile\MemoryAbi::ARRAY_HEADER_SIZE + \Compile\MemoryAbi::ARRAY_ENTRY_VALUE_OFFSET) . "\n";
        $ha = $this->allocSsa();
        $out .= '  ' . $ha . ' = getelementptr inbounds i8, ptr ' . $arr . ', i64 ' . $ho . "\n";
        $addr = $this->allocSsa();
        $out .= '  ' . $addr . ' = select i1 ' . $ish . ', ptr ' . $ha . ', ptr ' . $pa . "\n";
        $this->feAddr = $addr;
        return $out;
    }

    private function emitSwitch(Node $n): string
    {
        $sw = $this->castSwitch($n);
        $out = $this->emitNode($sw->subject);
        $out .= $this->coerceToI64();
        $subj = $this->lastValue;
        $endLabel = $this->allocLabel('sw.end');
        $savedBreak = $this->breakLabel;
        $this->breakLabel = $endLabel;
        // A switch counts as a break/continue level; continue inside a
        // switch behaves as break (target = end).
        $this->breakStack[] = $endLabel;
        $this->continueStack[] = $endLabel;

        // String subjects must compare by value (strcmp), not pointer.
        // Mirrors emitCmp's strish gate: subject string-or-unknown and the
        // arm value string-or-unknown, with at least one known string.
        $subjK = $sw->subject->type->kind;
        $subjStrish = $subjK === Type::KIND_STRING || $subjK === Type::KIND_UNKNOWN;

        $arms = $sw->arms;
        $count = \count($arms);
        // Per-switch label base — labels are derived by concatenation
        // from a position counter (not stored/read from string lists,
        // which self-host mis-reads as i64; not written onto the arm
        // objects, which self-host can't type from a foreach value).
        $base = 'sw' . (string)$this->switchCounter;
        $this->switchCounter = $this->switchCounter + 1;

        // Pass 1 — locate the default arm + count value arms.
        $defaultAi = -1;
        $nv = 0;
        $ai = 0;
        foreach ($arms as $arm) {
            if ($arm->value === null) { $defaultAi = $ai; }
            else { $nv = $nv + 1; }
            $ai = $ai + 1;
        }
        $defaultTarget = $defaultAi >= 0 ? ($base . '_b' . (string)$defaultAi) : $endLabel;
        $firstTarget = $nv > 0 ? ($base . '_t0') : $defaultTarget;

        // Dispatch — chained equality tests over the value arms.
        $out .= '  br label %' . $firstTarget . "\n";
        $ai = 0;
        $vi = 0;
        foreach ($arms as $arm) {
            if ($arm->value !== null) {
                $out .= $base . '_t' . (string)$vi . ":\n";
                $out .= $this->emitNode($arm->value);
                $vk = $arm->value->type->kind;
                $eq = $this->allocSsa();
                if ($subjK === Type::KIND_CELL) {
                    // A cell (untyped/`mixed`) subject is NaN-boxed, so a raw
                    // `icmp eq` of its boxed bits against a raw arm value never
                    // matches (a boxed int 1 != raw 1) and misses `5 == "5"`.
                    // PHP `switch` matches with `==`, so box the arm and run the
                    // loose-juggling tagged compare (mirrors emitCmp's cell path).
                    $out .= $this->boxToCell($arm->value->type);
                    $armCell = $this->lastValue;
                    $this->needsTaggedEq = true;
                    $this->needsTagged = true;
                    $this->needsTaggedToFloat = true;
                    $le = $this->allocSsa();
                    $out .= '  ' . $le . ' = call i64 @__manticore_tagged_loose_eq(i64 '
                          . $subj . ', i64 ' . $armCell . ")\n";
                    $out .= '  ' . $eq . ' = icmp ne i64 ' . $le . ", 0\n";
                } else {
                    $out .= $this->coerceToI64();
                    $v = $this->lastValue;
                    $useStr = ($subjK === Type::KIND_STRING || $vk === Type::KIND_STRING)
                        && $subjStrish && ($vk === Type::KIND_STRING || $vk === Type::KIND_UNKNOWN);
                    if ($useStr) {
                        $this->needsStrcmp = true;
                        $sp = $this->allocSsa();
                        $out .= '  ' . $sp . ' = inttoptr i64 ' . $subj . " to ptr\n";
                        $vp = $this->allocSsa();
                        $out .= '  ' . $vp . ' = inttoptr i64 ' . $v . " to ptr\n";
                        $out .= '  ' . $eq . ' = call i1 @__mir_str_eq(ptr ' . $sp . ', ptr ' . $vp . ")\n";
                    } else {
                        $out .= '  ' . $eq . ' = icmp eq i64 ' . $subj . ', ' . $v . "\n";
                    }
                }
                $miss = ($vi + 1 < $nv) ? ($base . '_t' . (string)($vi + 1)) : $defaultTarget;
                $out .= '  br i1 ' . $eq . ', label %' . $base . '_b' . (string)$ai
                      . ', label %' . $miss . "\n";
                $vi = $vi + 1;
            }
            $ai = $ai + 1;
        }
        // Bodies in source order; each falls through to the next
        // (PHP switch fall-through). `break` jumps to end.
        $ai = 0;
        foreach ($arms as $arm) {
            $out .= $base . '_b' . (string)$ai . ":\n";
            foreach ($arm->body as $s) { $out .= $this->emitNode($s); $out .= $this->emitDiscardedCallRelease($s); }
            $fall = ($ai + 1 < $count) ? ($base . '_b' . (string)($ai + 1)) : $endLabel;
            $out .= '  br label %' . $fall . "\n";
            $ai = $ai + 1;
        }
        $out .= $endLabel . ":\n";
        \array_pop($this->breakStack);
        \array_pop($this->continueStack);
        $this->breakLabel = $savedBreak;
        return $out;
    }

    private function emitMatch(Node $n): string
    {
        $m = $this->castMatch($n);
        $res = $this->allocSsa();
        $out = '  ' . $res . " = alloca i64\n";
        $out .= $this->emitNode($m->subject);
        $out .= $this->coerceToI64();
        $subj = $this->lastValue;
        // String subjects must compare by value (strcmp), not pointer.
        $subjK = $m->subject->type->kind;
        $subjStrish = $subjK === Type::KIND_STRING || $subjK === Type::KIND_UNKNOWN;
        // Heterogeneous arms (see inferMatch) → box each arm to a uniform cell.
        $wantCell = $n->type->kind === Type::KIND_CELL;
        // A boxed-cell subject (e.g. an untyped `$x` param) carries NaN-boxed
        // bits — a raw `icmp eq` against a literal cond NEVER matches, so every
        // arm fell through to default. Compare by tag instead: int/bool conds vs
        // the unboxed int payload, string conds via a tag-guarded strcmp.
        $subjIsCell = $subjK === Type::KIND_CELL;
        $subjInt = '';   // lazily-unboxed int carrier (cell subject, scalar cond)
        $endLabel = $this->allocLabel('match.end');
        foreach ($m->arms as $arm) {
            $bodyLabel = $this->allocLabel('match.body');
            $afterLabel = $this->allocLabel('match.after');
            $conds = $arm->conds;
            if ($conds === null) {
                $out .= '  br label %' . $bodyLabel . "\n";
            } else {
                foreach ($conds as $c) {
                    $vk = $this->nodeTypeKind($c);
                    $eq = $this->allocSsa();
                    if ($subjIsCell) {
                        if ($vk === Type::KIND_STRING || $vk === Type::KIND_UNKNOWN) {
                            // string cond: tag-guarded strcmp (a non-string
                            // subject is never strictly === a string).
                            $out .= $this->emitCellStrEq($subj, $c, $eq);
                        } else {
                            // int/bool/null cond: unbox the subject's payload
                            // once, then `icmp eq` against the raw cond value.
                            if ($subjInt === '') {
                                $this->needsTagged = true;
                                $subjInt = $this->allocSsa();
                                $out .= '  ' . $subjInt . ' = call i64 @__manticore_unbox_int(i64 ' . $subj . ")\n";
                            }
                            $out .= $this->emitNode($c);
                            $out .= $this->coerceToI64();
                            $out .= '  ' . $eq . ' = icmp eq i64 ' . $subjInt . ', ' . $this->lastValue . "\n";
                        }
                    } else {
                        $out .= $this->emitNode($c);
                        $out .= $this->coerceToI64();
                        $cv = $this->lastValue;
                        $useStr = ($subjK === Type::KIND_STRING || $vk === Type::KIND_STRING)
                            && $subjStrish && ($vk === Type::KIND_STRING || $vk === Type::KIND_UNKNOWN);
                        if ($useStr) {
                            $this->needsStrcmp = true;
                            $sp = $this->allocSsa();
                            $out .= '  ' . $sp . ' = inttoptr i64 ' . $subj . " to ptr\n";
                            $cp = $this->allocSsa();
                            $out .= '  ' . $cp . ' = inttoptr i64 ' . $cv . " to ptr\n";
                            $out .= '  ' . $eq . ' = call i1 @__mir_str_eq(ptr ' . $sp . ', ptr ' . $cp . ")\n";
                        } else {
                            $out .= '  ' . $eq . ' = icmp eq i64 ' . $subj . ', ' . $cv . "\n";
                        }
                    }
                    $condNext = $this->allocLabel('match.cond');
                    $out .= '  br i1 ' . $eq . ', label %' . $bodyLabel . ', label %' . $condNext . "\n";
                    $out .= $condNext . ":\n";
                }
                $out .= '  br label %' . $afterLabel . "\n";
            }
            $out .= $bodyLabel . ":\n";
            $out .= $this->emitNode($arm->body);
            $out .= $wantCell ? $this->boxToCell($arm->body->type) : $this->coerceToI64();
            $out .= '  store i64 ' . $this->lastValue . ', ptr ' . $res . "\n";
            $out .= '  br label %' . $endLabel . "\n";
            $out .= $afterLabel . ":\n";
        }
        // No arm matched (no default) — yield 0 (PHP throws; we don't).
        $out .= '  br label %' . $endLabel . "\n";
        $out .= $endLabel . ":\n";
        $loaded = $this->allocSsa();
        $out .= '  ' . $loaded . ' = load i64, ptr ' . $res . "\n";
        $this->lastValue = $loaded;
        $this->lastValueType = 'i64';
        if ($n->type->kind === Type::KIND_FLOAT) {
            $regF = $this->allocSsa();
            $out .= '  ' . $regF . ' = bitcast i64 ' . $loaded . " to double\n";
            $this->lastValue = $regF;
            $this->lastValueType = 'double';
        }
        return $out;
    }

    /**
     * Strict `cell === string`: leaves an i1 in `$eq`. The cell subject `$subj`
     * (boxed i64) equals the string cond iff its NaN tag is PTR (4) and the
     * bytes match — a non-string cell is never strictly === a string. Mirrors
     * the `string === cell` path in {@see emitCmp}.
     */
    private function emitCellStrEq(string $subj, Node $cond, string $eq): string
    {
        $this->needsStrcmp = true;
        $out = $this->emitNode($cond);
        $out .= $this->coerceToPtr();
        $cp = $this->lastValue;
        $out .= $this->cellTagIr($subj);
        $tag = $this->cellTagReg;
        $isStr = $this->allocSsa();
        $out .= '  ' . $isStr . ' = icmp eq i64 ' . $tag . ", 4\n";
        $cmpL = $this->allocLabel('match.streq');
        $nsL  = $this->allocLabel('match.strne');
        $jnL  = $this->allocLabel('match.strjoin');
        $out .= '  br i1 ' . $isStr . ', label %' . $cmpL . ', label %' . $nsL . "\n";
        $out .= $cmpL . ":\n";
        $payload = $this->allocSsa();
        $out .= '  ' . $payload . ' = and i64 ' . $subj . ", 281474976710655\n";
        $sp = $this->allocSsa();
        $out .= '  ' . $sp . ' = inttoptr i64 ' . $payload . " to ptr\n";
        $eqc = $this->allocSsa();
        $out .= '  ' . $eqc . ' = call i1 @__mir_str_eq(ptr ' . $sp . ', ptr ' . $cp . ")\n";
        $out .= '  br label %' . $jnL . "\n";
        $out .= $nsL . ":\n  br label %" . $jnL . "\n";
        $out .= $jnL . ":\n";
        $out .= '  ' . $eq . ' = phi i1 [ ' . $eqc . ', %' . $cmpL . ' ], [ false, %' . $nsL . " ]\n";
        return $out;
    }

    private function emitConcat(Node $n): string
    {
        $this->needsConcat = true;
        $c = $this->castConcat($n);
        // Route confined (Arena) concats through the arena allocator so
        // they are bulk-freed at the frame's mem_arena_leave; escaping
        // ones stay on the heap. The kind comes from InferAllocKind via
        // the MemoryOps contract — EmitLlvm never decides it here. The
        // operand int/float→string coercions are confined too, so they
        // bump-allocate alongside the concat buffer.
        $arena = $n->allocKind === \Compile\Mir\AllocationKind::ARENA;
        if ($arena) { $this->needsArena = true; }
        // Tier-1 fusion: a chain `a.b.c.d` lowers to nested Concat nodes, each
        // doing its own malloc+memcpy (N-1 mallocs, N-2 dead intermediates).
        // Flatten the chain to its leaf operands and build the result in ONE
        // malloc. Operand lengths stay on libc strlen (NOT len@-16) — fusion
        // touches only the allocation count, never the length-read path, so it
        // sidesteps the layout-sensitive self-host heisenbug.
        $ops = [];
        $this->flattenConcat($c, $ops);
        // Adjacent string literals merge into one (ConstFold only folds a fully
        // constant pair bottom-up, so `$x."a"."b"` still arrives as two lits):
        // fewer operands, fewer memcpys, and their length is compile-time known.
        $ops = $this->mergeAdjacentStrConsts($ops);
        if (count($ops) === 1) {
            // Everything merged to one literal (only if ConstFold somehow left
            // it) — just yield that value; immortal, nothing to free.
            $out = $this->emitNode($ops[0]);
            return $out . $this->coerceToStr($ops[0], $arena);
        }
        // The fused path formats int operands straight into the buffer (no
        // int_to_str temp). Use it for >2 operands, and for a 2-operand concat
        // that has an int operand (e.g. `"key".$i`, `$id.":"`); a pure string
        // pair keeps the single-__mir_concat fast path.
        if (count($ops) > 2 || $this->hasIntConcatOperand($ops)) {
            return $this->emitConcatFused($ops, $arena);
        }
        return $this->emitConcatPair($ops[0], $ops[1], $arena);
    }

    /** Two-operand concat via the __mir_concat runtime (the stable path). */
    private function emitConcatPair(Node $l, Node $r, bool $arena): string
    {
        $out = $this->emitNode($l);
        $out .= $this->coerceToStr($l, $arena);
        $lp = $this->lastValue;
        $out .= $this->emitNode($r);
        $out .= $this->coerceToStr($r, $arena);
        $rp = $this->lastValue;
        $reg = $this->allocSsa();
        $fn = $arena ? '@__mir_concat_arena' : '@__mir_concat';
        $out .= '  ' . $reg . ' = call ptr ' . $fn . '(ptr ' . $lp . ', ptr ' . $rp . ")\n";
        // The concat copied both operands' bytes; a freshly-produced operand
        // (int/float/bool coercion temp, or a nested concat / string-builtin
        // call result) is now dead and freed here. Borrowed operands (a
        // literal, a local, a property / element read) and cell coercions
        // (tagged_to_str may hand back a borrowed inner ptr) are left alone.
        $out .= $this->concatTempRelease($l, $lp);
        $out .= $this->concatTempRelease($r, $rp);
        $this->lastValue = $reg;
        $this->lastValueType = 'ptr';
        return $out;
    }

    /**
     * Collapse runs of adjacent string-literal operands into a single literal.
     * @param Node[] $ops
     * @return Node[]
     */
    private function mergeAdjacentStrConsts(array $ops): array
    {
        /** @var Node[] */
        $merged = [];
        foreach ($ops as $op) {
            $n = count($merged);
            if ($op->kind === Node::KIND_STRING_CONST && $n > 0
                && $merged[$n - 1]->kind === Node::KIND_STRING_CONST) {
                $prev = $this->castStringConst($merged[$n - 1]);
                $cur = $this->castStringConst($op);
                $merged[$n - 1] = new StringConst($prev->value . $cur->value, Type::string_());
                continue;
            }
            $merged[] = $op;
        }
        return $merged;
    }

    /**
     * Collapse a Concat tree to its ordered leaf operands. Nested concats are
     * flattened regardless of their own allocKind — fusion never materializes
     * a child buffer, it copies the child's leaf bytes straight into the one
     * fused result, so only the root's allocKind decides where that lives.
     * @param Node[] $ops
     */
    private function flattenConcat(Node $n, array &$ops): void
    {
        if ($n->kind === Node::KIND_CONCAT) {
            $c = $this->castConcat($n);
            $this->flattenConcat($c->left, $ops);
            $this->flattenConcat($c->right, $ops);
            return;
        }
        $ops[] = $n;
    }

    /**
     * Fused N-way concat: emit every operand once, sum their lengths, allocate
     * a single buffer, memcpy each in at a running offset, write one NUL. One
     * malloc instead of N-1; no dead intermediate strings to free.
     * @param Node[] $ops
     */
    private function emitConcatFused(array $ops, bool $arena): string
    {
        $empty = $this->strSymBytes('@.cstr.empty');
        $out = '';
        $raws = [];   // coerced operand temp (for the post-copy release); '' for int
        $gptrs = [];  // null→"" guarded ptr (what we memcpy from); '' for int
        $lens = [];   // byte length: a compile-time const for a literal, else strlen
        $intVals = []; // int operand's i64 value reg (formatted in-place), else ''
        foreach ($ops as $op) {
            // An int operand is formatted straight into the buffer via
            // __mir_int_fmt — no int_to_str temp string / memcpy / release.
            if ($op->type->kind === Type::KIND_INT) {
                $out .= $this->emitNode($op);
                $out .= $this->coerceToI64();
                $iv = $this->lastValue;
                $intVals[] = $iv;
                $raws[] = '';
                $gptrs[] = '';
                $l = $this->allocSsa();
                $out .= '  ' . $l . ' = call i64 @__mir_int_len(i64 ' . $iv . ")\n";
                $lens[] = $l;
                continue;
            }
            $intVals[] = '';
            $out .= $this->emitNode($op);
            $out .= $this->coerceToStr($op, $arena);
            $raw = $this->lastValue;
            $raws[] = $raw;
            // A string literal is never null and its length is known at compile
            // time — skip the null-guard AND the runtime strlen scan. Also
            // binary-safe: a literal with an embedded NUL keeps its true byte
            // length here, where libc strlen would truncate it.
            if ($op->kind === Node::KIND_STRING_CONST) {
                $gptrs[] = $raw;
                $lens[] = (string) \strlen($this->castStringConst($op)->value);
                continue;
            }
            // A null `?string` operand concatenates as "" (PHP), not a memcpy
            // of null — map 0 to the empty C-string, exactly like __mir_concat.
            $nn = $this->allocSsa();
            $out .= '  ' . $nn . ' = icmp eq ptr ' . $raw . ", null\n";
            $g = $this->allocSsa();
            $out .= '  ' . $g . ' = select i1 ' . $nn . ', ptr ' . $empty
                  . ', ptr ' . $raw . "\n";
            $gptrs[] = $g;
            // O(1) binary-safe length (len@-16) with a libc-strlen fallback for
            // a raw operand — same contract as __mir_concat.
            $l = $this->allocSsa();
            $out .= '  ' . $l . ' = call i64 @__mir_strlen(ptr ' . $g . ")\n";
            $lens[] = $l;
        }
        $sum = $lens[0];
        $n = count($lens);
        for ($i = 1; $i < $n; $i++) {
            $ns = $this->allocSsa();
            $out .= '  ' . $ns . ' = add i64 ' . $sum . ', ' . $lens[$i] . "\n";
            $sum = $ns;
        }
        $sz = $this->allocSsa();
        $out .= '  ' . $sz . ' = add i64 ' . $sum . ", 1\n";
        $alloc = $arena ? '@__mir_str_alloc_arena' : '@__mir_str_alloc';
        $buf = $this->allocSsa();
        $out .= '  ' . $buf . ' = call ptr ' . $alloc . '(i64 ' . $sz . ")\n";
        // Copy each operand at a running offset: an int operand is formatted
        // in place (__mir_int_fmt), a string operand is memcpy'd.
        $off = '0';
        for ($i = 0; $i < $n; $i++) {
            if ($intVals[$i] !== '') {
                $out .= '  call void @__mir_int_fmt(ptr ' . $buf . ', i64 '
                      . $off . ', i64 ' . $intVals[$i] . ")\n";
            } elseif ($off === '0') {
                $out .= '  call ptr @memcpy(ptr ' . $buf . ', ptr ' . $gptrs[$i]
                      . ', i64 ' . $lens[$i] . ")\n";
            } else {
                $d = $this->allocSsa();
                $out .= '  ' . $d . ' = getelementptr inbounds i8, ptr ' . $buf
                      . ', i64 ' . $off . "\n";
                $out .= '  call ptr @memcpy(ptr ' . $d . ', ptr ' . $gptrs[$i]
                      . ', i64 ' . $lens[$i] . ")\n";
            }
            if ($i < $n - 1) {
                $no = $this->allocSsa();
                $out .= '  ' . $no . ' = add i64 ' . $off . ', ' . $lens[$i] . "\n";
                $off = $no;
            }
        }
        $dend = $this->allocSsa();
        $out .= '  ' . $dend . ' = getelementptr inbounds i8, ptr ' . $buf
              . ', i64 ' . $sum . "\n";
        $out .= '  store i8 0, ptr ' . $dend . "\n";
        foreach ($ops as $i => $op) {
            // Int operands were formatted in place — no temp to release.
            if ($intVals[$i] !== '') { continue; }
            $out .= $this->concatTempRelease($op, $raws[$i]);
        }
        $this->lastValue = $buf;
        $this->lastValueType = 'ptr';
        return $out;
    }

    /** @param Node[] $ops  Any operand an int (formatted in-place by the fused path)? */
    private function hasIntConcatOperand(array $ops): bool
    {
        foreach ($ops as $op) {
            if ($op->type->kind === Type::KIND_INT) { return true; }
        }
        return false;
    }

    /** Release a fresh (owned) concat operand temp; '' for a borrow. */
    private function concatTempRelease(Node $op, string $ptr): string
    {
        $tk = $op->type->kind;
        if ($tk === Type::KIND_INT || $tk === Type::KIND_FLOAT
            || $tk === Type::KIND_BOOL) {
            // int/float_to_str coercion temp — always fresh.
            $this->needsStrRc = true;
            return '  call void @__mir_rc_release_str(ptr ' . $ptr . ")\n";
        }
        return $this->freeStrTemp($op, $ptr);
    }

    /**
     * A string that was just produced fresh (concat result or an owned
     * call/builtin return) — not a borrow (literal / local / property /
     * element read). Such a value, once consumed (a concat operand, a
     * borrowed call argument), is dead and can be freed.
     */
    private function isFreshStringTemp(Node $node): bool
    {
        if ($node->type->kind !== Type::KIND_STRING) { return false; }
        $k = $node->kind;
        return $k === Node::KIND_CONCAT || $k === Node::KIND_CALL
            || $k === Node::KIND_METHOD_CALL || $k === Node::KIND_STATIC_CALL
            || $k === Node::KIND_INVOKE;
    }

    /** Release `$ptr` iff `$node` is a fresh owned string temp; else ''. */
    private function freeStrTemp(Node $node, string $ptr): string
    {
        if (!$this->isFreshStringTemp($node)) { return ''; }
        $this->needsStrRc = true;
        return '  call void @__mir_rc_release_str(ptr ' . $ptr . ")\n";
    }

    /**
     * Emit a MemoryOp from the plan (#5). Arena scope enter/leave map
     * to real runtime calls; rc release/retain stay no-ops until the rc
     * runtime lands.
     */
    private function emitMemoryOp(Node $n): string
    {
        $mo = $this->castMemoryOp($n);
        if ($mo->op === 'arena_enter') {
            $this->needsArena = true;
            $this->currentFnHasArena = true;
            return "  call void @__mir_arena_enter()\n";
        }
        if ($mo->op === 'arena_leave') {
            // Fall-through exit: this runs just before the function's
            // implicit `ret`. After an explicit `return` it lands in a
            // dead block (harmless) — that path's leave is emitted by
            // emitReturn instead.
            $this->needsArena = true;
            return "  call void @__mir_arena_leave()\n";
        }
        if ($mo->op === 'rc_release') {
            // Scope-exit drop of an owned RcHeap vec / obj local.
            $t = $mo->target;
            if ($t !== null && $t->kind === Node::KIND_LOAD_LOCAL) {
                $name = $this->castLoadLocal($t)->name;
                // Transferred (escaped into a borrowing container): ownership
                // moved to the container, so skip the scope-exit release.
                if (isset($this->transferredLocals[$name])) { return ''; }
                if (isset($this->slots[$name])) {
                    return $this->rcReleaseSlot($this->slots[$name], $this->rcReleaseFlavor($mo));
                }
            }
            return '';
        }
        return '';
    }

    /**
     * A2 verify check, emitted inside an obj/vec release helper right after
     * `%rc = load`: abort if `%rc < 1` (releasing an already-dead value =
     * double-free / use-after-free). Returns '' (byte-identical IR) unless
     * MANTICORE_DEBUG_VERIFY is set. Labels are function-scoped, so the fixed
     * names are safe across the distinct release helpers.
     */
    private function rcVerifyAlive(): string
    {
        if (!\Compile\Debug::$verify) { return ''; }
        $out  = "  %vbad = icmp slt i64 %rc, 1\n";
        $out .= "  br i1 %vbad, label %vcorrupt, label %vok\n";
        $out .= "vcorrupt:\n";
        $out .= "  call void @abort()\n";
        $out .= "  unreachable\n";
        $out .= "vok:\n";
        return $out;
    }

    /**
     * Refine a vec rc_release flavor: a `vec[obj]` becomes `vecobj` so
     * its obj elements are released before the buffer is freed. All other
     * flavors pass through unchanged.
     */
    private function rcReleaseFlavor(\Compile\Mir\MemoryOp_ $mo): string
    {
        $t = $mo->target;
        // A shared buffer (passed by value as a call arg) is co-owned by the
        // callee along with its retained element refs — drop the buffer only,
        // never the elements (element-drop would double-free the shared refs:
        // the parser `$args` UAF). See {@see $elementSharedLocals}.
        $shared = $t !== null && $t->kind === Node::KIND_LOAD_LOCAL
            && isset($this->elementSharedLocals[$this->castLoadLocal($t)->name]);
        if ($mo->flavor === 'vec') {
            if ($t === null || $shared) { return 'vec'; }
            $el = $t->type->element;
            if ($el !== null && $el->kind === Type::KIND_CELL) { return 'veccell'; }
            if ($el !== null && $el->kind === Type::KIND_OBJ && !$this->isEnumClass($el->class ?? '')) { return 'vecobj'; }
            if ($el !== null && $el->kind === Type::KIND_STRING) { return 'vecstr'; }
            return 'vec';
        }
        if ($mo->flavor === 'assoc') {
            if ($t === null || $shared) { return 'assoc'; }
            $el = $t->type->element;
            if ($el !== null && $el->kind === Type::KIND_CELL) { return 'assoccell'; }
            if ($el !== null && $el->kind === Type::KIND_OBJ && !$this->isEnumClass($el->class ?? '')) { return 'assocobj'; }
            if ($el !== null && $el->kind === Type::KIND_STRING) { return 'assocstr'; }
            return 'assoc';
        }
        return $mo->flavor;
    }

    /**
     * Emit a retain of the rc value held in `$slot`, by the same flavor
     * vocabulary as {@see rcReleaseSlot}. Buffer-rc only: a vecobj/assocobj
     * retain bumps the container header (its elements keep their own refs),
     * mirroring how the paired release decrements the header. Used to
     * co-own a reassigned param's borrowed incoming value on entry.
     */
    private function rcRetainSlot(string $slot, string $flavor): string
    {
        if ($flavor === 'str') { $this->needsStrRc = true; $fn = '@__mir_rc_retain_str'; }
        elseif ($flavor === 'obj') { $this->needsRc = true; $fn = '@__mir_rc_retain'; }
        else { $fn = '@__mir_array_retain'; } // any vec/assoc flavor → unified buffer rc
        $iv = $this->allocSsa();
        $pv = $this->allocSsa();
        $out  = '  ' . $iv . ' = load i64, ptr ' . $slot . "\n";
        $out .= '  ' . $pv . ' = inttoptr i64 ' . $iv . " to ptr\n";
        $out .= '  call void ' . $fn . '(ptr ' . $pv . ")\n";
        return $out;
    }

    /** Emit a release of the rc value held in `$slot` (obj / vec / vecobj / str). */
    private function rcReleaseSlot(string $slot, string $flavor): string
    {
        $iv = $this->allocSsa();
        $out = '  ' . $iv . ' = load i64, ptr ' . $slot . "\n";
        return $out . $this->rcReleaseReg($iv, $flavor);
    }

    /** Emit a release of the rc value carried in the i64 register `$i64reg`. */
    private function rcReleaseReg(string $i64reg, string $flavor): string
    {
        // Every vec/assoc flavor releases through the one __mir_array_release*
        // (mode-driven; drops hashed string keys, and the _obj/_str variants
        // drop element values). str/obj scalars keep their own helpers.
        $fn = '@__mir_array_release';
        if ($flavor === 'str') { $this->needsStrRc = true; $fn = '@__mir_rc_release_str'; }
        elseif ($flavor === 'obj') { $this->needsRc = true; $fn = '@__mir_rc_release'; }
        elseif ($flavor === 'vecobj' || $flavor === 'assocobj') { $fn = '@__mir_array_release_obj'; }
        elseif ($flavor === 'vecstr' || $flavor === 'assocstr') { $fn = '@__mir_array_release_str'; }
        elseif ($flavor === 'veccell' || $flavor === 'assoccell') { $this->needsRc = true; $this->needsStrRc = true; $fn = '@__mir_array_release_cell'; }
        $pv = $this->allocSsa();
        $out  = '  ' . $pv . ' = inttoptr i64 ' . $i64reg . " to ptr\n";
        $out .= '  call void ' . $fn . '(ptr ' . $pv . ")\n";
        return $out;
    }

    /**
     * Flavor string for releasing an rc-managed value of type `$t`, or
     * '' when `$t` is not rc-managed (scalar / void / #[Struct] / closure).
     * Mirrors the {@see rcReleaseReg} vocabulary.
     */
    private function discardReleaseFlavor(Type $t): string
    {
        $k = $t->kind;
        if ($k === Type::KIND_STRING) { return 'str'; }
        if ($k === Type::KIND_OBJ) {
            $cls = $t->class ?? '';
            if ($cls !== '' && isset($this->classes[$cls]) && $this->classes[$cls]->isStruct) { return ''; }
            if ($this->isClosureClass($cls)) { return ''; }
            if ($this->isEnumClass($cls)) { return ''; }
            // A Generator frame carries a string-style rc header (rc@-8, free
            // base = ptr-16) — release it via the str rc path so the frame
            // buffer is freed on its last reference.
            if ($cls === 'Generator') { return 'str'; }
            return 'obj';
        }
        if ($t->isVec()) {
            $el = $t->element;
            if ($el !== null && $el->kind === Type::KIND_CELL) { return 'veccell'; }
            if ($el !== null && $el->kind === Type::KIND_OBJ && !$this->isEnumClass($el->class ?? '')) { return 'vecobj'; }
            if ($el !== null && $el->kind === Type::KIND_STRING) { return 'vecstr'; }
            return 'vec';
        }
        if ($t->isAssoc()) {
            $el = $t->element;
            if ($el !== null && $el->kind === Type::KIND_CELL) { return 'assoccell'; }
            if ($el !== null && $el->kind === Type::KIND_OBJ && !$this->isEnumClass($el->class ?? '')) { return 'assocobj'; }
            if ($el !== null && $el->kind === Type::KIND_STRING) { return 'assocstr'; }
            return 'assoc';
        }
        return '';
    }

    /**
     * Set the rc-runtime flags for every non-struct class property's release
     * flavor, so the helpers drop_dispatch references (vec / assoc element
     * walkers, str rc) are emitted. Runs before any helper is built (top of
     * emitPreamble). Mirrors {@see rcReleaseReg}'s flag vocabulary.
     */
    private function scanDropFlags(): void
    {
        foreach ($this->classes as $cls) {
            if ($cls->isStruct) { continue; }
            foreach ($cls->propertyNames as $pn) {
                $pt = $cls->propertyTypes[$pn] ?? null;
                if ($pt === null) { continue; }
                $flavor = $this->discardReleaseFlavor($pt);
                // Unified arrays: every vec/assoc flavor releases via
                // __mir_array_release* whose deps (needsRc/needsStrRc) are
                // forced unconditionally in emit(); str/obj likewise covered.
                if ($flavor !== '') { $this->needsRc = true; $this->needsStrRc = true; }
            }
        }
    }

    /** Release-helper symbol for a flavor (no side effects; flags are set in
     *  {@see scanDropFlags}). '' for a non-rc flavor. */
    private function dropHelperFor(string $flavor): string
    {
        if ($flavor === 'str') { return '@__mir_rc_release_str'; }
        if ($flavor === 'obj') { return '@__mir_rc_release'; }
        if ($flavor === 'vecobj' || $flavor === 'assocobj') { return '@__mir_array_release_obj'; }
        if ($flavor === 'vecstr' || $flavor === 'assocstr') { return '@__mir_array_release_str'; }
        if ($flavor === 'veccell' || $flavor === 'assoccell') { return '@__mir_array_release_cell'; }
        if ($flavor === 'vec' || $flavor === 'assoc') { return '@__mir_array_release'; }
        return '';
    }

    /**
     * Flavor for freeing a fresh owned obj/vec/assoc temp passed as a
     * borrow argument, or '' when the arg is not a guaranteed-owned (+1)
     * producer. Owned producers: `new`, array literal, method / static
     * call, and user free-function call (a builtin may return a borrowed
     * element — `current()` etc. — so it is excluded, as is a closure
     * invoke). Mirrors {@see isFreshStringTemp} for the string flavor.
     */
    private function freshRcArgFlavor(Node $a): string
    {
        $tk = $a->type->kind;
        if ($tk !== Type::KIND_OBJ && $tk !== Type::KIND_ARRAY) { return ''; }
        $k = $a->kind;
        // An array literal is always a fresh +1 (obj/vec/assoc alike).
        if ($k === Node::KIND_ARRAY_LIT) { return $this->discardReleaseFlavor($a->type); }
        // assoc returns are NOT +1 under the return convention
        // (isBorrowedObjReturn covers only obj/vec/string) — a method may
        // hand back a borrowed assoc. Only obj/vec call results are owned.
        if ($a->type->isAssoc()) { return ''; }
        $owned = $k === Node::KIND_NEW_OBJ
              || $k === Node::KIND_METHOD_CALL || $k === Node::KIND_STATIC_CALL;
        if ($k === Node::KIND_CALL) {
            $fn = $this->castCall($a)->function;
            $owned = isset($this->fnParamTypes[$fn]) && !($this->fnReturnsByRef[$fn] ?? false);
        }
        if (!$owned) { return ''; }
        return $this->discardReleaseFlavor($a->type);
    }

    /**
     * A bare method / static-call statement discards its return value.
     * Under the +1 owned-return convention an rc-managed result leaks
     * unless released here — this is the caller-side half of the
     * convention (the stored-result path releases at scope exit).
     * Free-function calls are excluded: builtins don't uniformly own
     * their result (some return borrowed elements), so releasing them
     * could over-release. User methods / static calls always +1.
     */
    private function emitDiscardedCallRelease(Node $s): string
    {
        $k = $s->kind;
        if ($k === Node::KIND_CALL) {
            // Free-function call: only a USER function reliably +1-owns its
            // result. Builtins vary (some return borrowed elements) — and a
            // user fn can never shadow a builtin name (PHP forbids it), so a
            // hit in fnParamTypes proves it is user-defined, not a builtin.
            $fname = $this->castCall($s)->function;
            if (!isset($this->fnParamTypes[$fname])) { return ''; }
            // A by-ref-returning fn yields an address, not an owned value.
            if ($this->fnReturnsByRef[$fname] ?? false) { return ''; }
        } elseif ($k !== Node::KIND_METHOD_CALL && $k !== Node::KIND_STATIC_CALL) {
            return '';
        }
        $flavor = $this->discardReleaseFlavor($s->type);
        if ($flavor === '') { return ''; }
        return $this->rcReleaseReg($this->lastValue, $flavor);
    }


    private function castMemoryOp(Node $n): \Compile\Mir\MemoryOp_ { return $n; }

    /**
     * Retain (rc++) a just-emitted vec / obj value that is being given a
     * second owner (heap store, container element, obj alias, capture).
     * `$i64reg` is the value in the i64 carrier. No-op for non-rc types.
     * Keeps escaping (RcHeap) values alive until every owner releases —
     * the soundness counterpart to the scope-exit rc_release.
     */
    /**
     * Emit a co-owner retain for a raw i64 value of a known static type — no
     * value node (used by `clone`'s slot copy). Skips non-rc kinds and the
     * header-less foreign/struct/closure objects.
     */
    private function rcRetainRawByType(string $i64reg, ?Type $t): string
    {
        if ($t === null) { return ''; }
        $tk = $t->kind;
        if ($tk === Type::KIND_OBJ) {
            $cls = $t->class ?? '';
            if ($cls === 'Ffi\\Ptr' || $cls === 'Generator' || $this->isClosureClass($cls)) { return ''; }
            if ($cls !== '' && isset($this->classes[$cls]) && $this->classes[$cls]->isStruct) { return ''; }
            if ($this->isEnumClass($cls)) { return ''; }
            $this->needsRc = true;
            $p = $this->allocSsa();
            $o  = '  ' . $p . ' = inttoptr i64 ' . $i64reg . " to ptr\n";
            $o .= '  call void @__mir_rc_retain(ptr ' . $p . ")\n";
            return $o;
        }
        if ($tk === Type::KIND_STRING) {
            $this->needsStrRc = true;
            $p = $this->allocSsa();
            $o  = '  ' . $p . ' = inttoptr i64 ' . $i64reg . " to ptr\n";
            $o .= '  call void @__mir_rc_retain_str(ptr ' . $p . ")\n";
            return $o;
        }
        if ($tk === Type::KIND_ARRAY) {
            $p = $this->allocSsa();
            $o  = '  ' . $p . ' = inttoptr i64 ' . $i64reg . " to ptr\n";
            $o .= '  call void @__mir_array_retain(ptr ' . $p . ")\n";
            return $o;
        }
        return '';
    }

    /**
     * Co-owner retain for a borrowed rc payload boxed into a CELL array slot.
     * A cell array stores the value by pointer (box_ptr / box_object keep the
     * payload ptr); without a retain the payload is freed by its source local's
     * scope-exit release while the array still references it — the int+substr
     * assoc scramble / UAF. Only string / obj / union box in place (a concrete
     * vec/assoc is REBUILT fresh by boxToCell, so it must NOT be retained);
     * {@see rcRetainByType} further skips owned producers (call/concat/new)
     * whose fresh +1 transfers. Preserves lastValue across the coercion so the
     * following boxToCell sees the original payload.
     */
    private function retainCellPayload(Node $value): string
    {
        $k = $value->type->kind;
        // A borrowed CELL-array (element cell/unknown) is boxed by ptr — NOT
        // rebuilt — so the cell co-owns it and needs a retain to balance the
        // tag7 release in __mir_cell_drop (rcRetainByType skips a fresh literal /
        // spread). A concrete-element array IS rebuilt fresh by boxToCell, so it
        // must NOT be retained (that new +1 is the cell's outright).
        $el = $value->type->element ?? null;
        $borrowedCellArray = $k === Type::KIND_ARRAY
            && ($el === null || $el->kind === Type::KIND_CELL || $el->kind === Type::KIND_UNKNOWN);
        if ($k !== Type::KIND_STRING && $k !== Type::KIND_OBJ && $k !== Type::KIND_UNION
            && !$borrowedCellArray) {
            return '';
        }
        $saveV = $this->lastValue;
        $saveT = $this->lastValueType;
        $out = $this->coerceToI64();
        $out .= $this->rcRetainByType($value, $this->lastValue, null, 2);
        $this->lastValue = $saveV;
        $this->lastValueType = $saveT;
        return $out;
    }

    private function rcRetainByType(Node $valueNode, string $i64reg, ?Type $fallback = null, int $cat = 6): string
    {
        // By-handle rc for obj / vec / string / assoc (buffer rc).
        $tk = $valueNode->type->kind;
        $cls = $valueNode->type->class ?? '';
        // A KIND_UNION value is a bare object pointer (an all-object union — its
        // arms are concrete classes); rc-manage it exactly like KIND_OBJ so a
        // borrowed union read stored into an obj slot/array gets a co-owner
        // retain to balance the obj release. Without this the array's
        // release_obj over-frees the borrowed arm → double-free.
        if ($tk === Type::KIND_UNION) { $tk = Type::KIND_OBJ; $cls = ''; }
        // Value type erased to unknown but the destination (e.g. a property)
        // is a known rc-managed kind → co-own per the destination's type.
        if (($tk === Type::KIND_UNKNOWN || $tk === Type::KIND_CELL) && $fallback !== null) {
            $fk = $fallback->kind;
            if ($fk === Type::KIND_OBJ || $fk === Type::KIND_ARRAY
                || $fk === Type::KIND_STRING) {
                $tk = $fk;
                $cls = $fallback->class ?? '';
            }
        }
        if ($tk !== Type::KIND_OBJ && $tk !== Type::KIND_ARRAY
            && $tk !== Type::KIND_STRING) { return ''; }
        // #[Struct] classes and closures have no rc header (a closure
        // struct is [fn_ptr, captures...] — offset 8 is a capture, not an
        // rc word) — never rc-manage them.
        if ($tk === Type::KIND_OBJ) {
            $scls = $cls;
            if ($scls !== '' && isset($this->classes[$scls]) && $this->classes[$scls]->isStruct) {
                return '';
            }
            if ($this->isClosureClass($scls)) { return ''; }
            if ($this->isEnumClass($scls)) { return ''; }
            // A Generator frame uses a string-style rc header (rc@-8) — retain
            // it via the str path (treat as KIND_STRING below). The owned vs
            // borrowed logic still applies: a gen() call / $g() invoke is a
            // fresh owned +1 (skipped), only an alias gets a co-owner retain.
            if ($scls === 'Generator') { $tk = Type::KIND_STRING; }
        }
        // An owned producer (`new` / array-literal / concat / call return)
        // carries a fresh +1 that transfers to the new owner — retaining it
        // would over-count. Only borrowed values (alias / property / array
        // read) and owned locals need a retain to add a co-owner.
        $k = $valueNode->kind;
        if ($k === Node::KIND_CALL || $k === Node::KIND_METHOD_CALL
            || $k === Node::KIND_STATIC_CALL || $k === Node::KIND_INVOKE) {
            return '';
        }
        if ($tk === Type::KIND_OBJ && ($k === Node::KIND_NEW_OBJ || $k === Node::KIND_CLONE)) { return ''; }
        // An array literal / spread is a fresh +1 that transfers; only
        // borrowed arrays (alias / read) need a co-owner retain.
        if ($tk === Type::KIND_ARRAY && ($k === Node::KIND_ARRAY_LIT || $k === Node::KIND_SPREAD)) { return ''; }
        // String owned producer: a concat is a fresh +1; a literal is
        // immortal (retain is a sentinel no-op — skip it).
        if ($tk === Type::KIND_STRING
            && ($k === Node::KIND_CONCAT || $k === Node::KIND_STRING_CONST)) { return ''; }
        $p = $this->allocSsa();
        $out  = $this->profBump(7 + $cat);
        $out .= '  ' . $p . ' = inttoptr i64 ' . $i64reg . " to ptr\n";
        if ($tk === Type::KIND_STRING) {
            $this->needsStrRc = true;
            $out .= '  call void @__mir_rc_retain_str(ptr ' . $p . ")\n";
        } elseif ($tk === Type::KIND_ARRAY) {
            // ONE tag-guarded buffer-rc retain for every array value.
            $out .= '  call void @__mir_array_retain(ptr ' . $p . ")\n";
        } else {
            $this->needsRc = true;
            $out .= '  call void @__mir_rc_retain(ptr ' . $p . ")\n";
        }
        return $out;
    }

    /**
     * Materialize `$this->lastValue` as a string `ptr` for concat:
     * string stays as-is (inttoptr if carried as i64); int / bool /
     * other scalars route through `__mir_int_to_str`. Float text
     * isn't precise yet — it degrades to the integer formatter,
     * which is wrong for fractional values (tracked for a follow-up).
     */
    private function coerceToStr(Node $operand, bool $arena = false): string
    {
        if ($operand->type->kind === Type::KIND_STRING) {
            return $this->coerceToPtr();
        }
        // null in a string context → "" (PHP), not "0" from the int path.
        if ($operand->type->kind === Type::KIND_NULL) {
            $this->lastValue = $this->strSymBytes('@.cstr.empty');
            $this->lastValueType = 'ptr';
            return '';
        }
        // A tagged cell (mixed) → dispatch on its tag at runtime.
        if ($operand->type->kind === Type::KIND_CELL) {
            $this->needsTaggedToStr = true;
            $out = $this->coerceToI64();
            $reg = $this->allocSsa();
            $out .= '  ' . $reg . ' = call ptr @__manticore_tagged_to_str(i64 ' . $this->lastValue . ")\n";
            $this->lastValue = $reg;
            $this->lastValueType = 'ptr';
            return $out;
        }
        $ts = $this->toStringClassOf($operand);
        if ($ts !== '') {
            return $this->emitToStringCall($ts);
        }
        // When the result feeds an arena-bound consumer (an Arena concat),
        // the coercion buffer is confined too — bump-allocate it so it is
        // freed at the same scope exit instead of leaking on the heap.
        if ($operand->type->kind === Type::KIND_FLOAT) {
            $this->needsFloatStr = true;
            $out = $this->coerceTo('double');
            $reg = $this->allocSsa();
            $fn = $arena ? '@__mir_float_to_str_arena' : '@__mir_float_to_str';
            if ($arena) { $this->needsArena = true; }
            $out .= '  ' . $reg . ' = call ptr ' . $fn . '(double ' . $this->lastValue . ")\n";
            $this->lastValue = $reg;
            $this->lastValueType = 'ptr';
            return $out;
        }
        $this->needsIntStr = true;
        $out = $this->coerceToI64();
        $reg = $this->allocSsa();
        $fn = $arena ? '@__mir_int_to_str_arena' : '@__mir_int_to_str';
        if ($arena) { $this->needsArena = true; }
        $out .= '  ' . $reg . ' = call ptr ' . $fn . '(i64 ' . $this->lastValue . ")\n";
        $this->lastValue = $reg;
        $this->lastValueType = 'ptr';
        return $out;
    }

    private function emitCmp(Node $n): string
    {
        $c = $this->castCmp($n);
        // `X === null` / `!== null` (and == / !=) — null-ness is a
        // compile-time property of the operand's type (the i64 carrier
        // can't tell null from int 0 at runtime). Evaluate the non-null
        // side for its side effects, then yield the constant.
        $leftNull = $c->left->kind === Node::KIND_NULL_CONST;
        $rightNull = $c->right->kind === Node::KIND_NULL_CONST;
        $op = $c->op;
        $isEq = ($op === '===' || $op === '==');
        $isNe = ($op === '!==' || $op === '!=');
        if (($leftNull || $rightNull) && ($isEq || $isNe)) {
            $other = $leftNull ? $c->right : $c->left;
            // Pointer-carried operands (string / obj / vec / assoc / closure)
            // and unknown-typed ones (e.g. a `null`-initialised accumulator
            // that unions to `unknown`) are genuinely null at runtime when
            // their i64 carrier is 0. Compare the carrier instead of folding
            // to a compile-time constant from the static type — the fold is
            // only valid for scalars (int/float/bool can't carry null) and a
            // literally-null operand.
            $ok = $other->type->kind;
            // A `mixed`/cell operand carries its type in a NaN tag — a boxed
            // null (tag NULL=3) is NOT i64 0, so compare the tag at runtime.
            // (`$o === null` in an SPL offsetSet is the canonical case.)
            if (!($leftNull && $rightNull) && $ok === Type::KIND_CELL) {
                $out = $this->emitNode($other);
                $out .= $this->coerceToI64();
                $out .= $this->cellTagIr($this->lastValue);
                $tag = $this->cellTagReg;
                $r = $this->allocSsa();
                $out .= '  ' . $r . ' = icmp ' . ($isEq ? 'eq' : 'ne')
                      . ' i64 ' . $tag . ", 3\n";
                $z = $this->allocSsa();
                $out .= '  ' . $z . ' = zext i1 ' . $r . " to i64\n";
                $this->lastValue = $z;
                $this->lastValueType = 'i64';
                return $out;
            }
            $ptrCarried = $ok === Type::KIND_STRING || $ok === Type::KIND_OBJ
                || $ok === Type::KIND_ARRAY
                || $ok === Type::KIND_CLOSURE || $ok === Type::KIND_UNKNOWN;
            if (!($leftNull && $rightNull) && $ptrCarried) {
                $out = $this->emitNode($other);
                $out .= $this->coerceToI64();
                $r = $this->allocSsa();
                $out .= '  ' . $r . ' = icmp ' . ($isEq ? 'eq' : 'ne')
                      . ' i64 ' . $this->lastValue . ", 0\n";
                $z = $this->allocSsa();
                $out .= '  ' . $z . ' = zext i1 ' . $r . " to i64\n";
                $this->lastValue = $z;
                $this->lastValueType = 'i64';
                return $out;
            }
            $out = '';
            if (!$leftNull)  { $out .= $this->emitNode($c->left); }
            if (!$rightNull) { $out .= $this->emitNode($c->right); }
            $otherIsNull = ($leftNull && $rightNull) || $other->type->kind === Type::KIND_NULL;
            $res = $isEq ? ($otherIsNull ? '1' : '0') : ($otherIsNull ? '0' : '1');
            $this->lastValue = $res;
            $this->lastValueType = 'i64';
            return $out;
        }
        // `cell === false` / `!== false` (e.g. `strpos(...) === false`).
        // A NaN-boxed `int|false` is false iff its tag is BOOL(2); a
        // boxed int never equals false. Compare the tag, skip payload.
        $lCell = $c->left->type->kind === Type::KIND_CELL;
        $rCell = $c->right->type->kind === Type::KIND_CELL;
        $lFalse = $c->left->kind === Node::KIND_BOOL_CONST && !$this->asBoolConst($c->left)->value;
        $rFalse = $c->right->kind === Node::KIND_BOOL_CONST && !$this->asBoolConst($c->right)->value;
        if (($isEq || $isNe) && (($lCell && $rFalse) || ($rCell && $lFalse))) {
            $cellNode = $lCell ? $c->left : $c->right;
            $out = $this->emitNode($cellNode);
            $out .= $this->coerceToI64();
            $v = $this->lastValue;
            $out .= $this->cellTagIr($v);
            $tag = $this->cellTagReg;
            $cmpReg = $this->allocSsa();
            $pred = $isEq ? 'eq' : 'ne';
            $out .= '  ' . $cmpReg . ' = icmp ' . $pred . ' i64 ' . $tag . ", 2\n";
            $extReg = $this->allocSsa();
            $out .= '  ' . $extReg . ' = zext i1 ' . $cmpReg . " to i64\n";
            $this->lastValue = $extReg;
            $this->lastValueType = 'i64';
            return $out;
        }

        // `$x === []` / `$x !== []` — PHP compares arrays by content, so an
        // empty-array literal operand means "is $x empty?". Comparing the
        // (always-distinct) buffer pointers would make `[] !== []` true.
        // Use the length instead.
        $lEmptyLit = $this->isEmptyArrayLit($c->left);
        $rEmptyLit = $this->isEmptyArrayLit($c->right);
        if (($isEq || $isNe) && ($lEmptyLit || $rEmptyLit)) {
            if ($lEmptyLit && $rEmptyLit) {
                $this->lastValue = $isEq ? '1' : '0';
                $this->lastValueType = 'i64';
                return '';
            }
            $arrNode = $lEmptyLit ? $c->right : $c->left;
            $ak = $arrNode->type->kind;
            if ($ak === Type::KIND_ARRAY || $ak === Type::KIND_UNKNOWN) {
                $out = $this->emitNode($arrNode);
                $out .= $this->coerceToPtr();
                $len = $this->allocSsa();
                $out .= '  ' . $len . ' = load i64, ptr ' . $this->lastValue . "\n";
                $cmpReg = $this->allocSsa();
                $out .= '  ' . $cmpReg . ' = icmp ' . ($isEq ? 'eq' : 'ne')
                      . ' i64 ' . $len . ", 0\n";
                $extReg = $this->allocSsa();
                $out .= '  ' . $extReg . ' = zext i1 ' . $cmpReg . " to i64\n";
                $this->lastValue = $extReg;
                $this->lastValueType = 'i64';
                return $out;
            }
        }

        $out = $this->emitNode($c->left);
        $l = $this->lastValue;
        $lt = $this->lastValueType;
        $lk = $c->left->type->kind;
        $out .= $this->emitNode($c->right);
        $r = $this->lastValue;
        $rt = $this->lastValueType;
        $rk = $c->right->type->kind;

        // `cell === enum` / `enum === cell` — an enum case in a cell is
        // box_object(per-case singleton). Box the raw-ordinal enum operand to
        // its singleton cell too and compare carriers: same case → same global
        // → equal (identity); a non-enum cell or a different case differs.
        if ($isEq || $isNe) {
            $lEnum = $lk === Type::KIND_OBJ && isset($this->enums[$c->left->type->class ?? '']);
            $rEnum = $rk === Type::KIND_OBJ && isset($this->enums[$c->right->type->class ?? '']);
            if (($lk === Type::KIND_CELL && $rEnum) || ($lEnum && $rk === Type::KIND_CELL)) {
                if ($lEnum) {
                    $this->lastValue = $l; $this->lastValueType = $lt;
                    $out .= $this->boxToCell($c->left->type);
                    $l = $this->lastValue; $lt = $this->lastValueType;
                } else {
                    $this->lastValue = $r; $this->lastValueType = $rt;
                    $out .= $this->boxToCell($c->right->type);
                    $r = $this->lastValue; $rt = $this->lastValueType;
                }
                if ($lt === 'ptr') { $tmp = $this->allocSsa(); $out .= '  ' . $tmp . ' = ptrtoint ptr ' . $l . " to i64\n"; $l = $tmp; }
                if ($rt === 'ptr') { $tmp = $this->allocSsa(); $out .= '  ' . $tmp . ' = ptrtoint ptr ' . $r . " to i64\n"; $r = $tmp; }
                $cmpReg = $this->allocSsa();
                $out .= '  ' . $cmpReg . ' = icmp ' . ($isEq ? 'eq' : 'ne') . ' i64 ' . $l . ', ' . $r . "\n";
                $z = $this->allocSsa();
                $out .= '  ' . $z . ' = zext i1 ' . $cmpReg . " to i64\n";
                $this->lastValue = $z;
                $this->lastValueType = 'i64';
                return $out;
            }
        }

        // `string === cell` / `cell === string` (strict): equal iff the cell is
        // a string (NaN tag PTR=4) whose bytes match the known string. A
        // non-string cell is never strictly ===. (Loose ==/!= juggles types and
        // is left to the fallthrough.)
        $strictEq = $op === '===' || $op === '!==';
        if ($strictEq
            && (($lk === Type::KIND_STRING && $rk === Type::KIND_CELL)
                || ($lk === Type::KIND_CELL && $rk === Type::KIND_STRING))) {
            $this->needsStrcmp = true;
            $cellI = ($lk === Type::KIND_CELL) ? $l : $r;
            $cellT = ($lk === Type::KIND_CELL) ? $lt : $rt;
            $strV  = ($lk === Type::KIND_CELL) ? $r : $l;
            $strT  = ($lk === Type::KIND_CELL) ? $rt : $lt;
            $ci = $cellI;
            if ($cellT === 'ptr') { $ci = $this->allocSsa(); $out .= '  ' . $ci . ' = ptrtoint ptr ' . $cellI . " to i64\n"; }
            $sp = $strV;
            if ($strT !== 'ptr') { $sp = $this->allocSsa(); $out .= '  ' . $sp . ' = inttoptr i64 ' . $strV . " to ptr\n"; }
            $out .= $this->cellTagIr($ci); $tag = $this->cellTagReg;
            $isStr = $this->allocSsa(); $out .= '  ' . $isStr . ' = icmp eq i64 ' . $tag . ", 4\n";
            // Guard a null string carrier (a `?string` operand) — skip the deref.
            $stri = $strV;
            if ($strT === 'ptr') { $stri = $this->allocSsa(); $out .= '  ' . $stri . ' = ptrtoint ptr ' . $strV . " to i64\n"; }
            $spNN = $this->allocSsa(); $out .= '  ' . $spNN . ' = icmp ne i64 ' . $stri . ", 0\n";
            $can = $this->allocSsa(); $out .= '  ' . $can . ' = and i1 ' . $isStr . ', ' . $spNN . "\n";
            $cmpL = $this->allocLabel('streqc.cmp');
            $nsL = $this->allocLabel('streqc.ns');
            $jnL = $this->allocLabel('streqc.join');
            $out .= '  br i1 ' . $can . ', label %' . $cmpL . ', label %' . $nsL . "\n";
            $out .= $cmpL . ":\n";
            $payload = $this->allocSsa(); $out .= '  ' . $payload . ' = and i64 ' . $ci . ", 281474976710655\n";
            $cp = $this->allocSsa(); $out .= '  ' . $cp . ' = inttoptr i64 ' . $payload . " to ptr\n";
            $eqc = $this->allocSsa(); $out .= '  ' . $eqc . ' = call i1 @__mir_str_eq(ptr ' . $sp . ', ptr ' . $cp . ")\n";
            $out .= '  br label %' . $jnL . "\n";
            $out .= $nsL . ":\n  br label %" . $jnL . "\n";
            $out .= $jnL . ":\n";
            $phi = $this->allocSsa();
            $out .= '  ' . $phi . ' = phi i1 [ ' . $eqc . ', %' . $cmpL . ' ], [ false, %' . $nsL . " ]\n";
            $res = $phi;
            if ($op === '!==') { $res = $this->allocSsa(); $out .= '  ' . $res . ' = xor i1 ' . $phi . ", true\n"; }
            $z = $this->allocSsa(); $out .= '  ' . $z . ' = zext i1 ' . $res . " to i64\n";
            $this->lastValue = $z; $this->lastValueType = 'i64';
            return $out;
        }
        // `cell === float` / `float === cell` (strict): equal iff the cell is a
        // FLOAT (tag 6) whose value equals the float operand. A non-float cell is
        // never strictly === a float. Compare the unboxed double (a float cell is
        // raw double bits under canonical NaN-boxing) — NOT the carrier i64.
        if ($strictEq
            && (($lk === Type::KIND_FLOAT && $rk === Type::KIND_CELL)
                || ($lk === Type::KIND_CELL && $rk === Type::KIND_FLOAT))) {
            $this->needsTaggedToFloat = true;
            $ci    = ($lk === Type::KIND_CELL) ? $l : $r;
            $cellT = ($lk === Type::KIND_CELL) ? $lt : $rt;
            $fltV  = ($lk === Type::KIND_CELL) ? $r : $l;
            $fltT  = ($lk === Type::KIND_CELL) ? $rt : $lt;
            if ($cellT === 'ptr') { $cp = $this->allocSsa(); $out .= '  ' . $cp . ' = ptrtoint ptr ' . $ci . " to i64\n"; $ci = $cp; }
            $fd = $fltV;
            if ($fltT !== 'double') { $fd = $this->allocSsa(); $out .= '  ' . $fd . ' = bitcast i64 ' . $fltV . " to double\n"; }
            $out .= $this->cellTagIr($ci); $tag = $this->cellTagReg;
            $isFlt = $this->allocSsa(); $out .= '  ' . $isFlt . ' = icmp eq i64 ' . $tag . ", 6\n";
            $cd = $this->allocSsa(); $out .= '  ' . $cd . ' = call double @__manticore_tagged_to_double(i64 ' . $ci . ")\n";
            $eqf = $this->allocSsa(); $out .= '  ' . $eqf . ' = fcmp oeq double ' . $cd . ', ' . $fd . "\n";
            $res = $this->allocSsa(); $out .= '  ' . $res . ' = and i1 ' . $isFlt . ', ' . $eqf . "\n";
            if ($op === '!==') { $nn = $this->allocSsa(); $out .= '  ' . $nn . ' = xor i1 ' . $res . ", true\n"; $res = $nn; }
            $z = $this->allocSsa(); $out .= '  ' . $z . ' = zext i1 ' . $res . " to i64\n";
            $this->lastValue = $z; $this->lastValueType = 'i64';
            return $out;
        }
        // String ordering / equality → strcmp(l, r) <pred> 0. Fires when
        // one side is a known string and the other is a string OR unknown
        // (e.g. an `array $args` element whose element type was erased to
        // i64): without this the fallthrough does a POINTER compare, which
        // only accidentally matches interned literals and fails on runtime
        // strings (argv, file reads). A known non-string operand (int /
        // obj / …) is excluded — that stays a value/identity compare.
        $lStrish = $lk === Type::KIND_STRING || $lk === Type::KIND_UNKNOWN;
        $rStrish = $rk === Type::KIND_STRING || $rk === Type::KIND_UNKNOWN;
        if (($lk === Type::KIND_STRING || $rk === Type::KIND_STRING)
            && $lStrish && $rStrish) {
            $this->needsStrcmp = true;
            // i64 carriers for the null guard (a `?string` operand carries 0
            // when null at runtime, e.g. an unset `?string` field).
            $li = $l;
            if ($lt === 'ptr') { $li = $this->allocSsa(); $out .= '  ' . $li . ' = ptrtoint ptr ' . $l . " to i64\n"; }
            $ri = $r;
            if ($rt === 'ptr') { $ri = $this->allocSsa(); $out .= '  ' . $ri . ' = ptrtoint ptr ' . $r . " to i64\n"; }
            $lp = $this->allocSsa();
            $out .= '  ' . $lp . ' = inttoptr i64 ' . $li . " to ptr\n";
            $rp = $this->allocSsa();
            $out .= '  ' . $rp . ' = inttoptr i64 ' . $ri . " to ptr\n";
            // Equality (=== / == / !== / !=): null is a valid operand value
            // (a string is never == to null). strcmp(null, …) dereferences
            // address 0 — guard it: strcmp only when both carriers are
            // non-null; otherwise the result is the i64-carrier identity
            // (both null → equal, one null → unequal).
            if ($isEq || $isNe) {
                $lnz = $this->allocSsa();
                $out .= '  ' . $lnz . ' = icmp ne i64 ' . $li . ", 0\n";
                $rnz = $this->allocSsa();
                $out .= '  ' . $rnz . ' = icmp ne i64 ' . $ri . ", 0\n";
                $both = $this->allocSsa();
                $out .= '  ' . $both . ' = and i1 ' . $lnz . ', ' . $rnz . "\n";
                $scLbl = $this->allocLabel('streq.cmp');
                $idLbl = $this->allocLabel('streq.id');
                $jnLbl = $this->allocLabel('streq.join');
                $out .= '  br i1 ' . $both . ', label %' . $scLbl . ', label %' . $idLbl . "\n";
                $out .= $scLbl . ":\n";
                $eqr = $this->allocSsa();
                $out .= '  ' . $eqr . ' = call i1 @__mir_str_eq(ptr ' . $lp . ', ptr ' . $rp . ")\n";
                $scRes = $eqr;
                if ($isNe) {
                    $scRes = $this->allocSsa();
                    $out .= '  ' . $scRes . ' = xor i1 ' . $eqr . ", true\n";
                }
                $out .= '  br label %' . $jnLbl . "\n";
                $out .= $idLbl . ":\n";
                $idRes = $this->allocSsa();
                $out .= '  ' . $idRes . ' = icmp ' . ($isEq ? 'eq' : 'ne') . ' i64 ' . $li . ', ' . $ri . "\n";
                $out .= '  br label %' . $jnLbl . "\n";
                $out .= $jnLbl . ":\n";
                $phi = $this->allocSsa();
                $out .= '  ' . $phi . ' = phi i1 [ ' . $scRes . ', %' . $scLbl . ' ], [ ' . $idRes . ', %' . $idLbl . " ]\n";
                $extReg = $this->allocSsa();
                $out .= '  ' . $extReg . ' = zext i1 ' . $phi . " to i64\n";
                $this->lastValue = $extReg;
                $this->lastValueType = 'i64';
                return $out;
            }
            $call = $this->allocSsa();
            $out .= '  ' . $call . ' = call i64 @__mir_str_cmp(ptr ' . $lp . ', ptr ' . $rp . ")\n";
            $cmpReg = $this->allocSsa();
            $out .= '  ' . $cmpReg . ' = icmp ' . $this->cmpPredicate($c->op) . ' i64 ' . $call . ", 0\n";
            $extReg = $this->allocSsa();
            $out .= '  ' . $extReg . ' = zext i1 ' . $cmpReg . " to i64\n";
            $this->lastValue = $extReg;
            $this->lastValueType = 'i64';
            return $out;
        }

        // Both operands are statically CELL, EQ/NE — dispatch by tag with PHP
        // juggling at runtime (`5 == "5"`, non-interned `"x" === "x"`). A raw i64
        // compare only accidentally works for canonical-repr ints / interned
        // strings; it misses int-vs-numeric-string and non-interned strings.
        if (($isEq || $isNe)
            && $lk === Type::KIND_CELL && $rk === Type::KIND_CELL) {
            $this->needsTaggedEq = true;
            $this->needsTagged = true;
            $this->needsTaggedToFloat = true;
            $li = $l;
            if ($lt === 'ptr') { $li = $this->allocSsa(); $out .= '  ' . $li . ' = ptrtoint ptr ' . $l . " to i64\n"; }
            $ri = $r;
            if ($rt === 'ptr') { $ri = $this->allocSsa(); $out .= '  ' . $ri . ' = ptrtoint ptr ' . $r . " to i64\n"; }
            $fn = $strictEq ? '@__manticore_tagged_strict_eq' : '@__manticore_tagged_loose_eq';
            $eq = $this->allocSsa();
            $out .= '  ' . $eq . ' = call i64 ' . $fn . '(i64 ' . $li . ', i64 ' . $ri . ")\n";
            $res = $eq;
            if ($isNe) {
                $res = $this->allocSsa();
                $out .= '  ' . $res . ' = xor i64 ' . $eq . ", 1\n";
            }
            $this->lastValue = $res;
            $this->lastValueType = 'i64';
            return $out;
        }
        // Both operands are statically CELL (guaranteed NaN-boxed) in an ORDERING
        // compare — their runtime types (string / int / float) are only known at
        // runtime, so dispatch by tag: string→strcmp, else numeric. Without this a
        // string cell orders by raw pointer bits (sorting array_keys / an erased
        // mixed array). Eq/ne keep the existing tag/carrier paths above.
        if (!$isEq && !$isNe
            && $lk === Type::KIND_CELL && $rk === Type::KIND_CELL) {
            $this->needsTaggedCompare = true;
            $this->needsTagged = true;
            $this->needsTaggedToFloat = true;
            $this->needsStrcmp = true;
            $li = $l;
            if ($lt === 'ptr') { $li = $this->allocSsa(); $out .= '  ' . $li . ' = ptrtoint ptr ' . $l . " to i64\n"; }
            $ri = $r;
            if ($rt === 'ptr') { $ri = $this->allocSsa(); $out .= '  ' . $ri . ' = ptrtoint ptr ' . $r . " to i64\n"; }
            $cmp = $this->allocSsa();
            $out .= '  ' . $cmp . ' = call i64 @__manticore_tagged_compare(i64 ' . $li . ', i64 ' . $ri . ")\n";
            $cmpReg = $this->allocSsa();
            $out .= '  ' . $cmpReg . ' = icmp ' . $this->cmpPredicate($c->op) . ' i64 ' . $cmp . ", 0\n";
            $extReg = $this->allocSsa();
            $out .= '  ' . $extReg . ' = zext i1 ' . $cmpReg . " to i64\n";
            $this->lastValue = $extReg;
            $this->lastValueType = 'i64';
            return $out;
        }
        // Float comparison when either side carries a double.
        if ($lt === 'double' || $rt === 'double'
            || $lk === Type::KIND_FLOAT || $rk === Type::KIND_FLOAT) {
            $ld = $l;
            if ($lt !== 'double') {
                $ld = $this->allocSsa();
                $out .= '  ' . $ld . ' = sitofp i64 ' . $l . " to double\n";
            }
            $rd = $r;
            if ($rt !== 'double') {
                $rd = $this->allocSsa();
                $out .= '  ' . $rd . ' = sitofp i64 ' . $r . " to double\n";
            }
            $cmpReg = $this->allocSsa();
            $out .= '  ' . $cmpReg . ' = fcmp ' . $this->cmpPredicateF($c->op)
                  . ' double ' . $ld . ', ' . $rd . "\n";
            $extReg = $this->allocSsa();
            $out .= '  ' . $extReg . ' = zext i1 ' . $cmpReg . " to i64\n";
            $this->lastValue = $extReg;
            $this->lastValueType = 'i64';
            return $out;
        }

        // A tagged-cell operand (e.g. `strpos(...)` → int|false) carries
        // its int payload NaN-boxed; the raw carrier is meaningless in a
        // numeric compare. Unbox to the signed int (false → 0) so
        // `strpos(...) > 0` / `< 0` / `=== 3` work. Restrict to numeric
        // contexts — an ordering op, or eq/ne against an int/bool side —
        // so a `string|false` cell (`getenv`) isn't mangled. (`=== false`
        // returned above via the tag-compare branch.)
        $numericCtx = (!$isEq && !$isNe)
            || $rk === Type::KIND_INT || $rk === Type::KIND_BOOL
            || $lk === Type::KIND_INT || $lk === Type::KIND_BOOL;
        if ($lk === Type::KIND_CELL && $numericCtx) {
            if ($lt === 'ptr') { $tmp = $this->allocSsa(); $out .= '  ' . $tmp . ' = ptrtoint ptr ' . $l . " to i64\n"; $l = $tmp; $lt = 'i64'; }
            $this->needsTagged = true;
            $u = $this->allocSsa();
            $out .= '  ' . $u . ' = call i64 @__manticore_unbox_int(i64 ' . $l . ")\n";
            $l = $u; $lt = 'i64';
        }
        if ($rk === Type::KIND_CELL && $numericCtx) {
            if ($rt === 'ptr') { $tmp = $this->allocSsa(); $out .= '  ' . $tmp . ' = ptrtoint ptr ' . $r . " to i64\n"; $r = $tmp; $rt = 'i64'; }
            $this->needsTagged = true;
            $u = $this->allocSsa();
            $out .= '  ' . $u . ' = call i64 @__manticore_unbox_int(i64 ' . $r . ")\n";
            $r = $u; $rt = 'i64';
        }
        // Handle comparison (identity / equality of vec / assoc / obj
        // handles, e.g. `$x !== []`): the carrier is i64, so a ptr operand
        // (a fresh array-literal / alloc) must be ptrtoint'd first.
        if ($lt === 'ptr') {
            $lp = $this->allocSsa();
            $out .= '  ' . $lp . ' = ptrtoint ptr ' . $l . " to i64\n";
            $l = $lp;
        }
        if ($rt === 'ptr') {
            $rp = $this->allocSsa();
            $out .= '  ' . $rp . ' = ptrtoint ptr ' . $r . " to i64\n";
            $r = $rp;
        }
        $pred = $this->cmpPredicate($c->op);
        $cmpReg = $this->allocSsa();
        $out .= '  ' . $cmpReg . ' = icmp ' . $pred . ' i64 ' . $l . ', ' . $r . "\n";
        $extReg = $this->allocSsa();
        $out .= '  ' . $extReg . ' = zext i1 ' . $cmpReg . " to i64\n";
        $this->lastValue = $extReg;
        $this->lastValueType = 'i64';
        return $out;
    }

    private function isEmptyArrayLit(Node $n): bool
    {
        return $n->kind === Node::KIND_ARRAY_LIT
            && \count($this->castArrayLit($n)->elements) === 0;
    }

    private function cmpPredicateF(string $op): string
    {
        if ($op === '==' || $op === '===') { return 'oeq'; }
        if ($op === '!=' || $op === '!==') { return 'one'; }
        if ($op === '<')  { return 'olt'; }
        if ($op === '<=') { return 'ole'; }
        if ($op === '>')  { return 'ogt'; }
        if ($op === '>=') { return 'oge'; }
        return 'oeq';
    }

    private function cmpPredicate(string $op): string
    {
        if ($op === '==' || $op === '===') { return 'eq'; }
        if ($op === '!=' || $op === '!==') { return 'ne'; }
        if ($op === '<')  { return 'slt'; }
        if ($op === '<=') { return 'sle'; }
        if ($op === '>')  { return 'sgt'; }
        if ($op === '>=') { return 'sge'; }
        return 'eq';
    }

    /**
     * Resolving class of `__toString` for an expression's object type,
     * or '' if it isn't a Stringable object.
     */
    private function toStringClassOf(Node $e): string
    {
        if ($e->type->kind !== Type::KIND_OBJ) { return ''; }
        $cls = $e->type->class ?? '';
        if ($cls === '') { return ''; }
        return $this->resolveMethodClass($cls, '__toString');
    }

    /**
     * Given `$this->lastValue` holding an object, call its (already
     * resolved) `$tsClass::__toString` and leave the resulting string
     * ptr in `$this->lastValue`. Returns the IR.
     */
    private function emitToStringCall(string $tsClass): string
    {
        $out = $this->coerceToI64();
        $obj = $this->lastValue;
        $r = $this->allocSsa();
        $out .= '  ' . $r . ' = call i64 @manticore_' . $this->mangle($tsClass) . '____toString(i64 ' . $obj . ")\n";
        $p = $this->allocSsa();
        $out .= '  ' . $p . ' = inttoptr i64 ' . $r . " to ptr\n";
        $this->lastValue = $p;
        $this->lastValueType = 'ptr';
        return $out;
    }

    private function emitEcho(Node $n): string
    {
        $out = '';
        foreach ($this->castEcho($n)->exprs as $e) {
            $out .= $this->emitNode($e);
            $kind = $e->type->kind;
            // Stringable object → __toString, then print as a string.
            $ts = $this->toStringClassOf($e);
            if ($ts !== '') {
                $out .= $this->emitToStringCall($ts);
                $kind = Type::KIND_STRING;
            }
            // A NaN-boxed cell (e.g. `int|false` from strpos) dispatches
            // on its tag at runtime — int prints decimal, false / null
            // print nothing, matching PHP echo.
            if ($kind === Type::KIND_CELL) {
                $out .= $this->coerceToI64();
                $this->needsTaggedEcho = true;
                $out .= '  call void @__manticore_echo_tagged(i64 '
                      . $this->lastValue . ")\n";
                continue;
            }
            // Coerce the cursor to the printf arg type — a string
            // local arrives as the i64 slot payload and must be
            // inttoptr'd; a float bitcast back to double.
            // PHP `echo` of a bool prints "1" for true and "" (nothing) for
            // false — NOT "0". Emit `printf("%.*s", b?1:0, "1")`: the precision
            // arg gates whether the single "1" char prints.
            if ($kind === Type::KIND_BOOL) {
                $out .= $this->coerceToI64();
                $nz = $this->allocSsa();
                $w = $this->allocSsa();
                $reg = $this->allocSsa();
                $out .= '  ' . $nz . ' = icmp ne i64 ' . $this->lastValue . ", 0\n";
                $out .= '  ' . $w . ' = zext i1 ' . $nz . " to i32\n";
                $out .= '  ' . $reg . ' = call i32 (ptr, ...) @printf(ptr @.fmt.ds, i32 '
                      . $w . ', ptr @.str.one)' . "\n";
                continue;
            }
            if ($kind === Type::KIND_FLOAT) {
                $out .= $this->coerceTo('double');
                $fmt = '@.fmt.pg';
                $argType = 'double';
            } elseif ($kind === Type::KIND_STRING) {
                $out .= $this->coerceToPtr();
                // A null `?string` (ptr 0) echoes "" in PHP — map 0 → the empty
                // C-string so printf doesn't dereference null.
                $sp = $this->lastValue;
                $snn = $this->allocSsa();
                $ssafe = $this->allocSsa();
                $out .= '  ' . $snn . ' = icmp eq ptr ' . $sp . ", null\n";
                $out .= '  ' . $ssafe . ' = select i1 ' . $snn
                      . ', ptr ' . $this->strSymBytes('@.cstr.empty') . ', ptr ' . $sp . "\n";
                $this->lastValue = $ssafe;
                $fmt = '@.fmt.s';
                $argType = 'ptr';
            } else {
                $out .= $this->coerceToI64();
                $fmt = '@.fmt.d';
                $argType = 'i64';
            }
            $val = $this->lastValue;
            $reg = $this->allocSsa();
            $out .= '  ' . $reg . ' = call i32 (ptr, ...) @printf(ptr '
                  . $fmt . ', ' . $argType . ' ' . $val . ")\n";
            // A fresh string temp (`echo $a . $b`) is dead after printing.
            // ($val is a ptr in the string branch; freeStrTemp no-ops the
            // non-string branches, where $e is never a fresh string.)
            $out .= $this->freeStrTemp($e, $val);
        }
        return $out;
    }

    /**
     * Release every owned RcHeap obj local of the current function except
     * `$returnedLocal` (transferred). Slots are null-inited, so releasing
     * an unassigned one is a no-op.
     */
    private function emitRcReturnCleanup(?string $returnedLocal): string
    {
        $out = '';
        foreach ($this->currentRcObjLocals as $name => $mo) {
            if ($name === $returnedLocal) { continue; }
            if (isset($this->transferredLocals[$name])) { continue; }
            if (!isset($this->slots[$name])) { continue; }
            $out .= $this->rcReleaseSlot($this->slots[$name], $this->rcReleaseFlavor($mo));
        }
        return $out;
    }

    private function emitReturn(Node $n): string
    {
        $r = $this->castReturn($n);
        $v = $r->value;
        // Inside a generator, `return` FINISHES it (state = -1, resume → 0).
        // The return value (if any) is stashed in `retval` for getReturn().
        if ($this->inGenerator) {
            $out = '';
            if ($v !== null) {
                $out .= $this->emitNode($v);
                $out .= $this->coerceToI64();
                $out .= '  store i64 ' . $this->lastValue . ', ptr ' . $this->genRetvalPtr . "\n";
            }
            $out .= '  store i64 -1, ptr ' . $this->genStatePtr . "\n";
            return $out . "  ret i64 0\n" . $this->emitDeadLabel();
        }
        // Close the frame arena before every exit, so confined values
        // are freed on the path actually taken (the plan's trailing
        // arena_leave only covers fall-through). The return value is
        // escaping (RcHeap, heap-allocated), never arena, so freeing
        // the arena here can't touch it.
        $leave = $this->currentFnHasArena ? "  call void @__mir_arena_leave()\n" : '';
        // Drop every owned RcHeap obj local on this return path, except
        // the one being returned (ownership transfers to the caller). The
        // trailing fall-through release covers paths with no `return`.
        $returnedLocal = ($v !== null && $v->kind === Node::KIND_LOAD_LOCAL)
            ? $this->castLoadLocal($v)->name : null;
        $leave .= $this->emitRcReturnCleanup($returnedLocal);
        if ($v === null) {
            return $this->finishReturn('', '0', $leave);
        }
        // By-ref return: yield the *address* of the returned lvalue as i64.
        // `return $n` (a by-ref param forwards its held address, a plain local
        // returns its slot address) or `return $this->prop` (GEP to the field
        // slot). An unaddressable value falls through to the normal value path.
        if ($this->currentReturnsByRef) {
            $addrIr = $this->byRefAddrOf($v);
            if ($addrIr !== null) {
                return $this->finishReturn($addrIr, $this->lastValue, $leave);
            }
        }
        $out = $this->emitNode($v);
        // Uniform closure ABI: a closure returns a scalar as a tagged cell, so a
        // dynamic `callable` caller reads it by tag and a known caller unboxes to
        // the sig's concrete type ({@see emitInvoke}). Arrays/objects ride raw
        // (boxToCell would rebuild an array) — they fall through to the normal
        // return path below. Generators never reach here (the inGenerator branch
        // returns first).
        if ($this->currentFnIsClosure && $this->isCellBoxableArg($v->type)) {
            $out .= $this->boxToCell($v->type);
            return $this->finishReturn($out, $this->lastValue, $leave);
        }
        // An UNKNOWN-typed closure return is a raw scalar from the compiler's
        // integer-arithmetic-on-cells path (`$x * 2` where $x is a plain cell
        // param → arithType yields unknown → a raw `mul i64`). The uniform ABI
        // requires a tagged cell, and under canonical NaN-boxing a raw int with
        // a 0 header is misread as a double — so box it by its runtime repr.
        // A passthrough `return $x` of a cell param is typed CELL (handled
        // above), never reaches here; arrays/objects travel raw (below).
        if ($this->currentFnIsClosure && $v->type->kind === Type::KIND_UNKNOWN) {
            $this->needsTagged = true;
            $out .= $this->boxLastByRepr();
            return $this->finishReturn($out, $this->lastValue, $leave);
        }
        // A `mixed` / union (cell) return boxes the value to a tagged
        // cell unless it already is one.
        if ($this->currentReturnType !== null
            && $this->currentReturnType->kind === Type::KIND_CELL
            && $v->type->kind !== Type::KIND_CELL) {
            $out .= $this->boxToCell($v->type);
        } else {
            // A cell value returned where the declared type is concrete
            // (`return $mixed[$i]` from a `: int` fn) must be unboxed — else the
            // tagged bits flow back as the result (a boxed int read as a raw
            // i64). Mirrors the cell→param unboxing.
            if ($v->type->kind === Type::KIND_CELL && $this->currentReturnType !== null) {
                $out .= $this->unboxCellToType($this->currentReturnType);
            }
            // ABI: every fn returns i64. Coerce float / ptr through
            // the i64 carrier.
            $out .= $this->coerceToI64();
            // +1 return convention: a borrowed obj (param / alias /
            // property / array read) is retained so the caller owns a
            // reference. Owned producers (`new`, call return) and
            // owned-local transfers are already +1.
            if ($this->isBorrowedObjReturn($v, $returnedLocal)) {
                $out .= $this->rcRetainByType($v, $this->lastValue);
            }
        }
        return $this->finishReturn($out, $this->lastValue, $leave);
    }

    /**
     * Emit a function return, first running any enclosing `finally` bodies
     * (innermost first) — PHP runs `finally` on the return path. The return
     * value is already computed into `$valReg` (evaluated BEFORE finally, per
     * PHP order); `$leave` (arena_leave + rc cleanup) trails so locals stay
     * live during finally. finallyStack is cleared while inlining so a `return`
     * inside a finally exits directly (a finally-return overrides).
     */
    private function finishReturn(string $out, string $valReg, string $leave): string
    {
        if ($this->finallyStack !== []) {
            $saved = $this->finallyStack;
            $this->finallyStack = [];
            foreach (\array_reverse($saved) as $body) {
                foreach ($body as $s) { $out .= $this->emitNode($s); $out .= $this->emitDiscardedCallRelease($s); }
            }
            $this->finallyStack = $saved;
        }
        return $out . $leave . '  ret i64 ' . $valReg . "\n" . $this->emitDeadLabel();
    }

    /** Whether an obj/vec return value is a borrowed reference (needs +1). */
    private function isBorrowedObjReturn(Node $v, ?string $returnedLocal): bool
    {
        $tk = $v->type->kind;
        $isVec = $v->type->isVec();
        if ($tk !== Type::KIND_OBJ && !$isVec
            && $tk !== Type::KIND_STRING) { return false; }
        if ($tk === Type::KIND_OBJ && ($this->objTypeIsStruct($v->type)
            || $this->isClosureClass($v->type->class ?? ''))) { return false; }
        $k = $v->kind;
        if ($k === Node::KIND_CALL || $k === Node::KIND_METHOD_CALL
            || $k === Node::KIND_STATIC_CALL || $k === Node::KIND_INVOKE) {
            return false; // owned producer — already +1
        }
        if ($tk === Type::KIND_OBJ && ($k === Node::KIND_NEW_OBJ || $k === Node::KIND_CLONE)) { return false; }
        if ($isVec && ($k === Node::KIND_ARRAY_LIT || $k === Node::KIND_SPREAD)) { return false; }
        // A concat is an owned +1; a literal is immortal — neither needs a
        // borrow retain. (rcRetainByType also no-ops these, but short-
        // circuit here so the convention reads clearly.)
        if ($tk === Type::KIND_STRING
            && ($k === Node::KIND_CONCAT || $k === Node::KIND_STRING_CONST)) { return false; }
        if ($k === Node::KIND_LOAD_LOCAL && $returnedLocal !== null
            && isset($this->currentRcObjLocals[$returnedLocal])) {
            return false; // transfer of an owned local
        }
        return true; // param / alias / property / array read — borrow
    }

    /**
     * A closure value has NO rc header: the synthesized `__closure_N` struct
     * is [fn_ptr, captures...] (offset 8 is a capture, not an rc word), and a
     * `\Closure`-typed slot (class "Closure") holds exactly such a struct.
     * rc-managing either (retain/release/co-own) mis-routes through the
     * self-routing rc helpers and writes out of bounds into the neighbouring
     * allocation — the startup `$this->commands[$k]=$cmd` heisenbug, where
     * `Command::run(\Closure $h)` retained `$h` and clobbered the commands
     * array header. Never rc-manage a closure.
     */
    private function isClosureClass(string $cls): bool
    {
        return $cls === 'Closure' || \str_starts_with($cls, '__closure_');
    }

    /** An enum case is a value-type ORDINAL (no rc header) — never rc-managed,
     *  like an int. `$cls` is an obj type's class name. */
    private function isEnumClass(string $cls): bool
    {
        return $cls !== '' && isset($this->enums[$cls]);
    }

    private function objTypeIsStruct(Type $t): bool
    {
        $cls = $t->class ?? '';
        return $cls !== '' && isset($this->classes[$cls]) && $this->classes[$cls]->isStruct;
    }


    /**
     * Whether call arg `$a` at param index `$pi` (per the callee's `$mask`)
     * is passed by reference — true only for a by-ref param fed a plain
     * local (the address-of source). Shared by call / method / static call.
     */
    private function argIsByRef(array $mask, int $pi, Node $a): bool
    {
        return ($mask[$pi] ?? false) && $this->isByRefAddressable($a);
    }

    /**
     * IR computing the by-ref address for arg `$a`, leaving the i64 value
     * (a box pointer) in `$this->lastValue`. A by-ref param already holds
     * an address — forward it; a plain local passes its slot address. Call
     * only when {@see argIsByRef} is true.
     */
    /**
     * Emit IR for omitted trailing args of `$fnKey`, starting at param index
     * `$firstMissingIdx`. Produces the constant default value of each missing
     * param (or `i64 0` for a null/absent default) and records the argument-
     * list suffix in {@see $lastPadArgs}. No-op when the call already supplies
     * every param (or the callee signature is unknown). `$haveArgs` says the
     * caller's arg list is already non-empty (so the suffix needs a leading
     * comma); false for a zero-arg call whose first pad value opens the list.
     */
    private function emitDefaultArgPad(string $fnKey, int $firstMissingIdx, bool $haveArgs): string
    {
        $this->lastPadArgs = '';
        $ptypes = $this->fnParamTypes[$fnKey] ?? [];
        $pcount = \count($ptypes);
        if ($firstMissingIdx >= $pcount) { return ''; }
        $pdefs = $this->fnParamDefaults[$fnKey] ?? [];
        $out = '';
        $pi = $firstMissingIdx;
        while ($pi < $pcount) {
            $sep = ($haveArgs || $this->lastPadArgs !== '') ? ', ' : '';
            $def = $pdefs[$pi] ?? null;
            if ($def !== null) {
                $out .= $this->emitNode($def);
                $out .= $this->coerceToI64();
                $this->lastPadArgs .= $sep . 'i64 ' . $this->lastValue;
            } else {
                $this->lastPadArgs .= $sep . 'i64 0';
            }
            $pi = $pi + 1;
        }
        return $out;
    }

    /** Push a trace frame (`display` name + call-site `line`) before a user call;
     *  no-op unless the program queries traces. */
    private function btPush(string $display, int $line): string
    {
        if (!$this->needsBacktrace) { return ''; }
        return '  call void @__mir_bt_push(ptr ' . $this->strLitId($this->internString($display))
             . ', i64 ' . (string)$line . ")\n";
    }

    /** Pop the frame pushed by {@see btPush} after the call returns. */
    private function btPop(): string
    {
        return $this->needsBacktrace ? "  call void @__mir_bt_pop()\n" : '';
    }

    /**
     * Build a packed vec of the active call frames from `$global`
     * (@__mir_bt_name or @__mir_bt_line), innermost first (index depth-1 → 0);
     * lastValue ← the vec ptr as i64. Shared by the backtrace builtin and the
     * Throwable trace capture.
     */
    private function emitBtVec(string $global): string
    {
        $dep = $this->allocSsa();
        $out = '  ' . $dep . " = load i64, ptr @__mir_bt_depth\n";
        $slot = $this->allocSsa();
        $out .= '  ' . $slot . " = alloca ptr\n";
        $nv = $this->allocSsa();
        $out .= '  ' . $nv . ' = call ptr @__mir_array_alloc(i64 ' . $dep . ")\n";
        $out .= '  store ptr ' . $nv . ', ptr ' . $slot . "\n";
        $iSlot = $this->allocSsa();
        $out .= '  ' . $iSlot . " = alloca i64\n";
        $i0 = $this->allocSsa();
        $out .= '  ' . $i0 . ' = sub i64 ' . $dep . ", 1\n";
        $out .= '  store i64 ' . $i0 . ', ptr ' . $iSlot . "\n";
        $cond = $this->allocLabel('bt.cond');
        $body = $this->allocLabel('bt.body');
        $end  = $this->allocLabel('bt.end');
        $out .= '  br label %' . $cond . "\n" . $cond . ":\n";
        $i = $this->allocSsa();
        $out .= '  ' . $i . ' = load i64, ptr ' . $iSlot . "\n";
        $c = $this->allocSsa();
        $out .= '  ' . $c . ' = icmp sge i64 ' . $i . ", 0\n";
        $out .= '  br i1 ' . $c . ', label %' . $body . ', label %' . $end . "\n";
        $out .= $body . ":\n";
        $ep = $this->allocSsa();
        $out .= '  ' . $ep . ' = getelementptr inbounds [4096 x i64], ptr ' . $global . ', i64 0, i64 ' . $i . "\n";
        $ev = $this->allocSsa();
        $out .= '  ' . $ev . ' = load i64, ptr ' . $ep . "\n";
        $cur = $this->allocSsa();
        $out .= '  ' . $cur . ' = load ptr, ptr ' . $slot . "\n";
        $nx = $this->allocSsa();
        $out .= '  ' . $nx . ' = call ptr @__mir_array_append(ptr ' . $cur . ', i64 ' . $ev . ")\n";
        $out .= '  store ptr ' . $nx . ', ptr ' . $slot . "\n";
        $i2 = $this->allocSsa();
        $out .= '  ' . $i2 . ' = sub i64 ' . $i . ", 1\n";
        $out .= '  store i64 ' . $i2 . ', ptr ' . $iSlot . "\n";
        $out .= '  br label %' . $cond . "\n" . $end . ":\n";
        $dst = $this->allocSsa();
        $out .= '  ' . $dst . ' = load ptr, ptr ' . $slot . "\n";
        $r = $this->allocSsa();
        $out .= '  ' . $r . ' = ptrtoint ptr ' . $dst . " to i64\n";
        $this->lastValue = $r;
        $this->lastValueType = 'i64';
        return $out;
    }

    private function emitByRefArg(Node $a): string
    {
        return $this->byRefAddrOf($a) ?? '';
    }

    /**
     * Whether `$a` is an addressable lvalue that can be passed by reference:
     * a plain local with a stack slot, or an object property `$obj->prop`
     * whose class (hence field offset) is statically known. Decided WITHOUT
     * emitting (used by {@see argIsByRef}); {@see byRefAddrOf} does the emit.
     */
    private function isByRefAddressable(Node $a): bool
    {
        if ($a->kind === Node::KIND_LOAD_LOCAL) {
            return isset($this->slots[$this->castLoadLocal($a)->name]);
        }
        if ($a->kind === Node::KIND_PROPERTY_ACCESS) {
            $pa = $this->castPropertyAccess($a);
            $cls = $pa->object->type->class ?? '';
            return $cls !== '' && isset($this->classes[$cls]);
        }
        if ($a->kind === Node::KIND_ARRAY_ACCESS) {
            return $this->arrayElemAddressable($this->castArrayAccess($a));
        }
        return false;
    }

    /**
     * `$a[$k]` is by-ref addressable when it names an INT-keyed element of an
     * array container (a local, a global, or an object property). The runtime
     * ref-slot helper is int-only; string / cell keys and string-char indexing
     * (`$s[0]`) fall back to a value copy.
     */
    private function arrayElemAddressable(ArrayAccess_ $aa): bool
    {
        if (!$this->arrayElemKeyKind($aa->index)) { return false; }
        if (!$this->containerAddressable($aa->array)) { return false; }
        // Base must be a genuine (raw-pointer) array container. A bare-array
        // property read can infer UNKNOWN, so consult the declared prop type.
        if ($aa->array->type->isArray()) { return true; }
        if ($aa->array->kind === Node::KIND_PROPERTY_ACCESS) {
            $pa = $this->castPropertyAccess($aa->array);
            $cls = $pa->object->type->class ?? '';
            if ($cls !== '' && isset($this->classes[$cls])) {
                $pt = $this->classes[$cls]->propertyTypes[$pa->property] ?? null;
                if ($pt !== null && $pt->isArray()) { return true; }
            }
        }
        return false;
    }

    /** The runtime ref-slot helpers key on int or string; `true` when `$index`
     *  is one of those (a `null` append, cell, or float key is not addressable).
     *  Returns 'int' or 'str' for {@see byRefAddrOf}, or null when unsupported. */
    private function arrayElemKeyKind(Node $index): ?string
    {
        $k = $index->type->kind;
        if ($k === Type::KIND_INT || $index->kind === Node::KIND_INT_CONST) {
            return 'int';
        }
        if ($k === Type::KIND_STRING || $index->kind === Node::KIND_STRING_CONST) {
            return 'str';
        }
        return null;
    }

    /** Pure predicate: `$base` has a stable i64 cell holding its array pointer
     *  ({@see containerCellPtr} without emitting). */
    private function containerAddressable(Node $base): bool
    {
        if ($base->kind === Node::KIND_LOAD_LOCAL) {
            $name = $this->castLoadLocal($base)->name;
            return isset($this->globalBackedLocals[$name]) || isset($this->slots[$name]);
        }
        if ($base->kind === Node::KIND_PROPERTY_ACCESS) {
            $cls = $this->castPropertyAccess($base)->object->type->class ?? '';
            return $cls !== '' && isset($this->classes[$cls]);
        }
        return false;
    }

    /**
     * IR leaving a `ptr` to the i64 cell that holds `$base`'s array pointer in
     * `$this->lastValue` (a local's alloca, a by-ref param's forwarded slot, a
     * global cell, or an object field); null when `$base` has no such stable
     * cell. Used to feed `__mir_array_ref_slot` so a COW / relocation is stored
     * back where the array lives.
     */
    private function containerCellPtr(Node $base): ?string
    {
        if ($base->kind === Node::KIND_LOAD_LOCAL) {
            $name = $this->castLoadLocal($base)->name;
            if (isset($this->globalBackedLocals[$name])) {
                $this->lastValue = $this->globalBackedLocals[$name];
                $this->lastValueType = 'ptr';
                return '';
            }
            if (!isset($this->slots[$name])) { return null; }
            if (isset($this->refLocals[$name])) {
                // The slot holds the address of the caller's cell — deref once.
                $ai = $this->allocSsa();
                $out = '  ' . $ai . ' = load i64, ptr ' . $this->slots[$name] . "\n";
                $p = $this->allocSsa();
                $out .= '  ' . $p . ' = inttoptr i64 ' . $ai . " to ptr\n";
                $this->lastValue = $p;
                $this->lastValueType = 'ptr';
                return $out;
            }
            $this->lastValue = $this->slots[$name];
            $this->lastValueType = 'ptr';
            return '';
        }
        if ($base->kind === Node::KIND_PROPERTY_ACCESS) {
            // The property field IS the cell holding the array pointer.
            $addr = $this->byRefAddrOf($base);
            if ($addr === null) { return null; }
            $p = $this->allocSsa();
            $addr .= '  ' . $p . ' = inttoptr i64 ' . $this->lastValue . " to ptr\n";
            $this->lastValue = $p;
            $this->lastValueType = 'ptr';
            return $addr;
        }
        return null;
    }

    /**
     * IR computing the by-ref ADDRESS of lvalue `$a` as i64 in
     * `$this->lastValue`; null when `$a` is not addressable. A plain local
     * yields its slot address (a by-ref local already HOLDS an address — it is
     * forwarded); an object property `$obj->prop` yields a GEP to the field
     * slot, so the callee's writes to its `&$p` param mutate the property.
     */
    private function byRefAddrOf(Node $a): ?string
    {
        if ($a->kind === Node::KIND_LOAD_LOCAL) {
            $name = $this->castLoadLocal($a)->name;
            if (!isset($this->slots[$name])) { return null; }
            $addr = $this->allocSsa();
            if (isset($this->refLocals[$name])) {
                $out = '  ' . $addr . ' = load i64, ptr ' . $this->slots[$name] . "\n";
            } else {
                $out = '  ' . $addr . ' = ptrtoint ptr ' . $this->slots[$name] . " to i64\n";
            }
            $this->lastValue = $addr;
            $this->lastValueType = 'i64';
            return $out;
        }
        if ($a->kind === Node::KIND_PROPERTY_ACCESS) {
            $pa = $this->castPropertyAccess($a);
            $cls = $pa->object->type->class ?? '';
            if ($cls === '' || !isset($this->classes[$cls])) { return null; }
            $out = $this->emitNode($pa->object);
            $out .= $this->coerceToPtr();
            $objp = $this->lastValue;
            $off = $this->propertyOffset($pa->object, $pa->property);
            $g = $this->allocSsa();
            $out .= '  ' . $g . ' = getelementptr inbounds i8, ptr ' . $objp
                  . ', i64 ' . (string)$off . "\n";
            $addr = $this->allocSsa();
            $out .= '  ' . $addr . ' = ptrtoint ptr ' . $g . " to i64\n";
            $this->lastValue = $addr;
            $this->lastValueType = 'i64';
            return $out;
        }
        if ($a->kind === Node::KIND_ARRAY_ACCESS) {
            $aa = $this->castArrayAccess($a);
            $keyKind = $this->arrayElemKeyKind($aa->index);
            if ($keyKind === null || !$this->arrayElemAddressable($aa)) { return null; }
            // ptr to the cell holding the array (for COW write-back).
            $out = $this->containerCellPtr($aa->array);
            if ($out === null) { return null; }
            $slotPtr = $this->lastValue;
            $ep = $this->allocSsa();
            if ($keyKind === 'str') {
                $out .= $this->emitNode($aa->index);
                $out .= $this->coerceToPtr();
                $keyReg = $this->lastValue;
                $out .= '  ' . $ep . ' = call ptr @__mir_array_ref_slot_str(ptr '
                      . $slotPtr . ', ptr ' . $keyReg . ")\n";
            } else {
                $out .= $this->emitNode($aa->index);
                $out .= $this->coerceToI64();
                $keyReg = $this->lastValue;
                $out .= '  ' . $ep . ' = call ptr @__mir_array_ref_slot(ptr '
                      . $slotPtr . ', i64 ' . $keyReg . ")\n";
            }
            $addr = $this->allocSsa();
            $out .= '  ' . $addr . ' = ptrtoint ptr ' . $ep . " to i64\n";
            $this->lastValue = $addr;
            $this->lastValueType = 'i64';
            return $out;
        }
        return null;
    }

    /**
     * Unbox a just-emitted tagged-cell arg when the callee param is a raw
     * scalar (bool/int). A heterogeneous assoc (`['static' => false, ...]`)
     * stores its values boxed, so reading one yields a cell; passed to a
     * `bool`/`int` param it would otherwise carry the tag bits (a boxed
     * `false` is non-zero → reads truthy). Returns IR; updates lastValue.
     * `$mask` is the callee's per-param type table; `$pi` the param index.
     */
    private function unboxCellArg(Node $a, array $ptypes, int $pi): string
    {
        if ($a->type->kind !== Type::KIND_CELL) { return ''; }
        $pt = $ptypes[$pi] ?? null;
        if ($pt === null) { return ''; }
        return $this->unboxCellToType($pt);
    }

    /**
     * Unbox the cell currently in lastValue (i64) to the representation a
     * concrete target type `$pt` expects: bool → `& 1`, int → unbox_int,
     * array/string/object → strip the NaN tag to the payload pointer. Any other
     * kind (cell/float/unknown/…) is left as-is. Used at every cell→concrete
     * boundary (call arg, `return`): a cell carries tag bits a typed consumer
     * would mis-read (a boxed `false` is non-zero → truthy; a boxed array
     * inttoptr's the tagged bits → fault).
     */
    private function unboxCellToType(Type $pt): string
    {
        $pk = $pt->kind;
        if ($pk === Type::KIND_BOOL) {
            $r = $this->allocSsa();
            $out = '  ' . $r . ' = and i64 ' . $this->lastValue . ", 1\n";
            $this->lastValue = $r;
            $this->lastValueType = 'i64';
            return $out;
        }
        if ($pk === Type::KIND_INT) {
            $r = $this->allocSsa();
            $out = '  ' . $r . ' = call i64 @__manticore_unbox_int(i64 ' . $this->lastValue . ")\n";
            $this->lastValue = $r;
            $this->lastValueType = 'i64';
            return $out;
        }
        if ($pk === Type::KIND_FLOAT) {
            // A boxed cell is a NaN-pattern i64; reinterpreting it as a double
            // yields NaN. Unbox by tag to a real double (the caller coerces it
            // back through the i64 carrier for the ABI).
            $this->needsTaggedToFloat = true;
            $r = $this->allocSsa();
            $out = '  ' . $r . ' = call double @__manticore_tagged_to_double(i64 ' . $this->lastValue . ")\n";
            $this->lastValue = $r;
            $this->lastValueType = 'double';
            return $out;
        }
        if ($pk === Type::KIND_OBJ && $pt->class !== null && isset($this->enums[$pt->class])) {
            // Enum cell → the ORDINAL a typed-enum consumer expects. The cell is
            // box_object(singleton); mask to the data ptr, load ordinal @+16
            // (mirrors emitEnumCellSingletons' layout), NOT the raw payload.
            $m = $this->allocSsa();
            $out = '  ' . $m . ' = and i64 ' . $this->lastValue . ", 281474976710655\n";
            $p = $this->allocSsa();
            $out .= '  ' . $p . ' = inttoptr i64 ' . $m . " to ptr\n";
            $g = $this->allocSsa();
            $out .= '  ' . $g . ' = getelementptr i8, ptr ' . $p . ", i64 16\n";
            $r = $this->allocSsa();
            $out .= '  ' . $r . ' = load i64, ptr ' . $g . "\n";
            $this->lastValue = $r;
            $this->lastValueType = 'i64';
            return $out;
        }
        if ($pk === Type::KIND_ARRAY || $pk === Type::KIND_STRING
            || $pk === Type::KIND_OBJ) {
            $r = $this->allocSsa();
            $out = '  ' . $r . ' = and i64 ' . $this->lastValue . ", 281474976710655\n";
            $this->lastValue = $r;
            $this->lastValueType = 'i64';
            return $out;
        }
        return '';
    }

    private function emitCall(Node $n): string
    {
        $c = $this->castCall($n);
        $b = $this->emitBuiltin($c);
        if ($b !== null) { return $b; }
        $out = '';
        $argList = '';
        $first = true;
        $mask = $this->fnRefParams[$c->function] ?? [];
        $tmask = $this->fnTaggedParams[$c->function] ?? [];
        $ptypes = $this->fnParamTypes[$c->function] ?? [];
        $ai = 0;
        // Fresh string-temp arg carriers freed after the call: a borrow the
        // callee retains if it keeps it (the +1 convention), so the caller's
        // transient is dead once the call returns.
        $argTemps = [];
        // Fresh owned obj/vec/assoc temps passed to a borrow param: same
        // borrow-everything contract as the string temps (a keeping callee
        // retains; see the retain categories) — the caller's transient is
        // dead once the call returns. Parallel reg/flavor arrays.
        $rcArgRegs = [];
        $rcArgFlavs = [];
        foreach ($c->args as $a) {
            // Argument unpacking `f(...$arr)`: expand the array into the callee's
            // remaining positional params (arr[0], arr[1], …). Fixed-arity; the
            // element values pass raw (matches int/string/cell params).
            if ($a->kind === Node::KIND_SPREAD) {
                $operand = $this->castSpread($a)->operand;
                $out .= $this->emitNode($operand);
                $out .= $this->coerceToPtr();
                $arr = $this->lastValue;
                $elemType = $operand->type->element ?? null;
                $nparams = \count($ptypes);
                $k = $ai;
                while ($k < $nparams) {
                    if (!$first) { $argList .= ', '; }
                    $first = false;
                    $ev = $this->allocSsa();
                    $out .= '  ' . $ev . ' = call i64 @__mir_array_value_at(ptr ' . $arr
                          . ', i64 ' . (string)($k - $ai) . ")\n";
                    // A cell/tagged param needs the raw element boxed by its
                    // source type (a homogeneous int/string vec stores values
                    // raw); a cell-valued source is already boxed → no rebox.
                    $pt = $ptypes[$k] ?? null;
                    $needBox = ($tmask[$k] ?? false)
                        || ($pt !== null && $pt->kind === Type::KIND_CELL);
                    if ($needBox && $elemType !== null
                        && $elemType->kind !== Type::KIND_CELL
                        && $elemType->kind !== Type::KIND_UNKNOWN) {
                        $this->lastValue = $ev;
                        $this->lastValueType = 'i64';
                        $out .= $this->boxToCell($elemType);
                        $ev = $this->lastValue;
                    }
                    $argList .= 'i64 ' . $ev;
                    $k = $k + 1;
                }
                $ai = $nparams;
                continue;
            }
            if (!$first) { $argList .= ', '; }
            $first = false;
            if (($mask[$ai] ?? false) && $this->isByRefAddressable($a)) {
                // By-ref param fed an addressable lvalue (plain local or
                // `$obj->prop`): pass the address so the callee's writes land
                // in the caller's slot / the object's field.
                $out .= $this->byRefAddrOf($a);
                $argList .= 'i64 ' . $this->lastValue;
            } elseif (($mask[$ai] ?? false) && $a->kind !== Node::KIND_LOAD_LOCAL) {
                // By-ref param with a non-lvalue arg — an OMITTED default
                // (`&$r = null` called without the arg) reaches here as the
                // filled default expr. Back it with a throwaway stack slot so
                // the callee's write lands somewhere (PHP discards it) instead
                // of dereferencing a null address.
                $tmp = $this->allocSsa();
                $out .= '  ' . $tmp . " = alloca i64\n";
                $out .= $this->emitNode($a);
                $out .= $this->coerceToI64();
                $out .= '  store i64 ' . $this->lastValue . ', ptr ' . $tmp . "\n";
                $addr = $this->allocSsa();
                $out .= '  ' . $addr . ' = ptrtoint ptr ' . $tmp . " to i64\n";
                $argList .= 'i64 ' . $addr;
            } elseif (($tmask[$ai] ?? false) && $a->type->kind !== Type::KIND_CELL) {
                // Tagged (mixed/union) param: NaN-box the arg by its
                // static type so the callee can read its runtime tag.
                $out .= $this->emitNode($a);
                $out .= $this->boxToCell($a->type);
                $argList .= 'i64 ' . $this->lastValue;
            } else {
                $out .= $this->emitNode($a);
                // An int/bool arg to a declared `float` param converts
                // numerically (sitofp) — else the integer bits bitcast through
                // the i64 ABI carrier and the callee reads a garbage double
                // (`number_format(5)`, `f(5)` for `f(float $x)`).
                $pt = $ptypes[$ai] ?? null;
                if ($pt !== null && $pt->kind === Type::KIND_FLOAT
                    && ($a->type->kind === Type::KIND_INT || $a->type->kind === Type::KIND_BOOL)) {
                    $out .= $this->coerceToI64();
                    $d = $this->allocSsa();
                    $out .= '  ' . $d . ' = sitofp i64 ' . $this->lastValue . " to double\n";
                    $this->lastValue = $d;
                    $this->lastValueType = 'double';
                }
                // ABI: every fn takes i64 args. Float / ptr values
                // cross the boundary as the bit-pattern in i64.
                $out .= $this->coerceToI64();
                $out .= $this->unboxCellArg($a, $ptypes, $ai);
                $argList .= 'i64 ' . $this->lastValue;
                if ($this->isFreshStringTemp($a)) {
                    $argTemps[] = $this->lastValue;
                } else {
                    $rf = $this->freshRcArgFlavor($a);
                    if ($rf !== '') { $rcArgRegs[] = $this->lastValue; $rcArgFlavs[] = $rf; }
                }
            }
            $ai = $ai + 1;
        }
        $reg = $this->allocSsa();
        $mangled = $this->mangle($c->function);
        // A `manticore_rt_*` callee with no PHP definition is a native
        // FFI-boundary primitive — declare it as an extern so the module
        // assembles (link-stubbed on the no-Rust bootstrap).
        if (!isset($this->definedFns[$mangled])
            && \substr($mangled, 0, 13) === 'manticore_rt_'
            && !isset($this->rtExterns[$mangled])
        ) {
            $ptypes = '';
            $pi = 0;
            foreach ($c->args as $ignored) {
                if ($pi > 0) { $ptypes .= ', '; }
                $ptypes .= 'i64';
                $pi = $pi + 1;
            }
            $this->rtExterns[$mangled] =
                'declare i64 @manticore_' . $mangled . '(' . $ptypes . ')';
        }
        // Backtrace frame around a user call (not an rt_ FFI primitive).
        $btName = '';
        if ($this->needsBacktrace && \substr($mangled, 0, 13) !== 'manticore_rt_') {
            $btName = $c->function;
            $bs = \strrpos($btName, '\\');
            if ($bs !== false) { $btName = \substr($btName, $bs + 1); }
            $out .= $this->btPush($btName, $n->line);
        }
        $out .= '  ' . $reg . ' = call i64 @manticore_' . $mangled
              . '(' . $argList . ")\n";
        if ($btName !== '') { $out .= $this->btPop(); }
        // Free fresh string-temp args now the callee has read (and retained
        // if kept) them. Skipped when the call returns one of them by ref.
        if (!($this->fnReturnsByRef[$c->function] ?? false)) {
            $out .= $this->freeStrArgTemps($argTemps);
            $ri = 0;
            foreach ($rcArgRegs as $rg) {
                $out .= $this->rcReleaseReg($rg, $rcArgFlavs[$ri]);
                $ri = $ri + 1;
            }
        }
        $this->lastValue = $reg;
        $this->lastValueType = 'i64';
        // By-ref-returning callee yields an address. In value context
        // (everything but a `$r = &fn()` bind) deref it to the value.
        if (($this->fnReturnsByRef[$c->function] ?? false) && !$this->rawRefCall) {
            $p = $this->allocSsa();
            $out .= '  ' . $p . ' = inttoptr i64 ' . $reg . " to ptr\n";
            $dv = $this->allocSsa();
            $out .= '  ' . $dv . ' = load i64, ptr ' . $p . "\n";
            $this->lastValue = $dv;
            $reg = $dv;
        }
        // If the inferred return type is float, bitcast the i64
        // back to a usable double for the caller side.
        if ($n->type->kind === Type::KIND_FLOAT) {
            $regF = $this->allocSsa();
            $out .= '  ' . $regF . ' = bitcast i64 ' . $reg . " to double\n";
            $this->lastValue = $regF;
            $this->lastValueType = 'double';
        }
        return $out;
    }

    /**
     * Ensure `$this->lastValue` is carried as i64. Doubles bitcast,
     * ptrs ptrtoint, ints pass through. Used at function-call
     * boundaries and `ret` sites.
     */
    /**
     * Emit a condition node and leave in lastValue an i64 that is 0/non-0 for
     * its truthiness, so the caller's `icmp ne i64 X, 0` is correct. A cell
     * (mixed) cond routes through __manticore_tagged_truthy (a boxed 0/false/""
     * has non-zero raw bits → would read truthy); any other type coerces to i64
     * unchanged (behaviour identical to the prior inline `emitNode + coerceToI64`).
     */
    private function emitCondVal(Node $cond): string
    {
        $out = $this->emitNode($cond);
        return $out . $this->truthinessOf($cond->type);
    }

    /**
     * Transform the current lastValue into an i64 that is 0/non-0 for its PHP
     * truthiness. A CELL unboxes by tag; a STRING is falsy for "" and "0" (a raw
     * ptr coerce would read any non-null string, incl. "", as truthy) — box it
     * (box_ptr is bit-ops, no alloc) and reuse the tagged-truthy byte check; any
     * other type coerces to i64 unchanged (the caller's `icmp ne 0` is correct).
     * Split from emitCondVal so the short-ternary (`?:`) can compute truthiness
     * WITHOUT clobbering the raw operand it reuses as its then-value.
     */
    private function truthinessOf(Type $t): string
    {
        if ($t->kind === Type::KIND_CELL) {
            $this->needsTaggedTruthy = true;
            $out = $this->coerceToI64();
            $r = $this->allocSsa();
            $out .= '  ' . $r . ' = call i64 @__manticore_tagged_truthy(i64 ' . $this->lastValue . ")\n";
            $this->lastValue = $r;
            $this->lastValueType = 'i64';
            return $out;
        }
        if ($t->kind === Type::KIND_STRING) {
            $out = $this->boxToCell(Type::string_());
            $this->needsTaggedTruthy = true;
            $r = $this->allocSsa();
            $out .= '  ' . $r . ' = call i64 @__manticore_tagged_truthy(i64 ' . $this->lastValue . ")\n";
            $this->lastValue = $r;
            $this->lastValueType = 'i64';
            return $out;
        }
        // An array is falsy iff empty (len 0); a raw ptr coerce reads any
        // non-null array (incl. `[]`) as truthy. Tag the raw ptr (box_array is
        // bit-ops + a null guard, no element rebuild) and reuse tagged-truthy's
        // length check.
        if ($t->isArray()) {
            $out = $this->coerceToPtr();
            $this->needsTagged = true;
            $ba = $this->allocSsa();
            $out .= '  ' . $ba . ' = call i64 @__manticore_box_array(ptr ' . $this->lastValue . ")\n";
            $this->needsTaggedTruthy = true;
            $r = $this->allocSsa();
            $out .= '  ' . $r . ' = call i64 @__manticore_tagged_truthy(i64 ' . $ba . ")\n";
            $this->lastValue = $r;
            $this->lastValueType = 'i64';
            return $out;
        }
        return $this->coerceToI64();
    }

    private function coerceToI64(): string
    {
        if ($this->lastValueType === 'i64') { return ''; }
        if ($this->lastValueType === 'double') {
            $reg = $this->allocSsa();
            $out = '  ' . $reg . ' = bitcast double ' . $this->lastValue . " to i64\n";
            $this->lastValue = $reg;
            $this->lastValueType = 'i64';
            return $out;
        }
        if ($this->lastValueType === 'ptr') {
            $reg = $this->allocSsa();
            $out = '  ' . $reg . ' = ptrtoint ptr ' . $this->lastValue . " to i64\n";
            $this->lastValue = $reg;
            $this->lastValueType = 'i64';
            return $out;
        }
        return '';
    }

    private function emitIf(Node $n): string
    {
        $i = $this->castIf($n);
        $out = $this->emitCondVal($i->cond);
        $cond = $this->lastValue;
        $thenLabel = $this->allocLabel('then');
        $elseLabel = $i->else === null ? $this->allocLabel('endif') : $this->allocLabel('else');
        $endLabel = $i->else === null ? $elseLabel : $this->allocLabel('endif');
        // Truncate i64 → i1 for the branch condition.
        $condBit = $this->allocSsa();
        $out .= '  ' . $condBit . ' = icmp ne i64 ' . $cond . ", 0\n";
        $out .= '  br i1 ' . $condBit . ', label %' . $thenLabel . ', label %' . $elseLabel . "\n";
        $out .= $thenLabel . ":\n";
        $out .= $this->emitNode($i->then);
        $out .= '  br label %' . $endLabel . "\n";
        if ($i->else !== null) {
            $out .= $elseLabel . ":\n";
            $out .= $this->emitNode($i->else);
            $out .= '  br label %' . $endLabel . "\n";
        }
        $out .= $endLabel . ":\n";
        return $out;
    }

    /** Set by arenaScan: the loop subtree contains an Arena allocation. */
    private bool $arenaHasAlloc = false;
    /** Set by arenaScan: an Arena value is bound to a NON-local sink
     *  (property / element / static / dyn prop) — always unsafe to reset,
     *  the value outlives the frame. */
    private bool $arenaBindsNonLocal = false;
    /** @var array<string,bool> locals a store binds an Arena value to in the
     *  loop — each must pass the reset-liveness check (written-before-read
     *  each iteration AND not read outside the loop). */
    private array $arenaBoundLocals = [];
    private string $arenaSaveCurReg = '';
    private string $arenaSaveUsedReg = '';

    /**
     * A loop may reset the arena each iteration iff its body (+cond/step)
     * allocates Arena temporaries and every Arena value it *binds* is safe to
     * free at the iteration boundary. Binding to a non-local sink (property /
     * element / static) is never safe. Binding to a LOCAL is safe when the
     * local is (A) written before it is read on each iteration (so the prior
     * iteration's freed value is never observed) AND (B) not read anywhere in
     * the function outside this loop (so the last iteration's value — freed by
     * the pre-exit reset — is never observed either). `$step` may be null.
     */
    private function loopArenaResettable(?Node $cond, Node $body, ?Node $step): bool
    {
        // A generator resume body re-enters mid-loop via the entry state
        // switch (irreducible CFG), so a per-iteration arena save placed
        // before the loop no longer dominates the in-loop reset. Disable the
        // arena loop optimization inside generators.
        if ($this->inGenerator) { return false; }
        $this->arenaHasAlloc = false;
        $this->arenaBindsNonLocal = false;
        $this->arenaBoundLocals = [];
        if ($cond !== null) { $this->arenaScan($cond); }
        $this->arenaScan($body);
        if ($step !== null) { $this->arenaScan($step); }
        if (!$this->arenaHasAlloc || $this->arenaBindsNonLocal) { return false; }
        foreach ($this->arenaBoundLocals as $name => $ignored) {
            // (B) read outside the loop? Reads within the loop are cond+body+step;
            // any surplus in the whole function body is an outside read.
            $inLoop = $this->countLocalReads($name, $body)
                + ($cond !== null ? $this->countLocalReads($name, $cond) : 0)
                + ($step !== null ? $this->countLocalReads($name, $step) : 0);
            $total = $this->currentFnBody !== null
                ? $this->countLocalReads($name, $this->currentFnBody) : $inLoop;
            if ($total > $inLoop) { return false; }
            // (A) written before read on each iteration.
            if (!$this->writtenBeforeRead($name, $body)) { return false; }
        }
        return true;
    }

    private function arenaScan(Node $n): void
    {
        if ($n->allocKind === \Compile\Mir\AllocationKind::ARENA) {
            $this->arenaHasAlloc = true;
        }
        if ($n->kind === Node::KIND_STORE_LOCAL) {
            $sl = $this->castStoreLocal($n);
            if ($this->bindsArenaValue($sl->value)) {
                $this->arenaBoundLocals[$sl->name] = true;
            }
        } else {
            $sv = $this->storeBoundValue($n);
            if ($sv !== null && $this->bindsArenaValue($sv)) {
                $this->arenaBindsNonLocal = true;
            }
        }
        foreach (\Compile\Mir\Walk::children($n) as $c) { $this->arenaScan($c); }
    }

    /** Count LOAD_LOCAL reads of `$name` in the subtree. */
    private function countLocalReads(string $name, Node $n): int
    {
        $c = 0;
        if ($n->kind === Node::KIND_LOAD_LOCAL && $this->castLoadLocal($n)->name === $name) {
            $c = 1;
        }
        foreach (\Compile\Mir\Walk::children($n) as $ch) {
            $c = $c + $this->countLocalReads($name, $ch);
        }
        return $c;
    }

    /**
     * Whether, in `$body`, `$name` is assigned by a plain StoreLocal (whose
     * value does NOT read `$name`) before any read of it — sound if the body
     * is a statement sequence: the first statement that mentions `$name` must
     * be that fresh assignment. Conservative (false) for any other first use
     * (a read, an element/compound store, or a self-referential value).
     */
    private function writtenBeforeRead(string $name, Node $body): bool
    {
        foreach ($this->stmtList($body) as $stmt) {
            if ($stmt->kind === Node::KIND_STORE_LOCAL
                && $this->castStoreLocal($stmt)->name === $name) {
                // Fresh re-init iff the value doesn't read $name itself.
                return $this->countLocalReads($name, $this->castStoreLocal($stmt)->value) === 0;
            }
            // Any other statement that mentions $name (read, or a nested/
            // conditional/element write) reaches a use before a clean write.
            if ($this->countLocalReads($name, $stmt) > 0
                || $this->mentionsLocalStore($name, $stmt)) {
                return false;
            }
        }
        return false;
    }

    /** Statement list of a block body (else a one-element list). */
    private function stmtList(Node $body): array
    {
        if ($body->kind === Node::KIND_BLOCK) {
            return $this->castBlock($body)->stmts;
        }
        return [$body];
    }

    /** Whether the subtree contains a StoreLocal targeting `$name` (any depth). */
    private function mentionsLocalStore(string $name, Node $n): bool
    {
        if ($n->kind === Node::KIND_STORE_LOCAL && $this->castStoreLocal($n)->name === $name) {
            return true;
        }
        foreach (\Compile\Mir\Walk::children($n) as $ch) {
            if ($this->mentionsLocalStore($name, $ch)) { return true; }
        }
        return false;
    }

    /** The value a store binds to a name, or null for a non-store node. */
    private function storeBoundValue(Node $n): ?Node
    {
        $k = $n->kind;
        if ($k === Node::KIND_STORE_LOCAL) { return $this->castStoreLocal($n)->value; }
        if ($k === Node::KIND_STORE_PROPERTY) { return $this->castStoreProperty($n)->value; }
        if ($k === Node::KIND_STORE_ELEMENT) { return $this->castStoreElement($n)->value; }
        if ($k === Node::KIND_STORE_STATIC_PROP) { return $this->castStoreStaticProp($n)->value; }
        if ($k === Node::KIND_STORE_DYN_PROP) { return $this->castStoreDynProp($n)->value; }
        return null;
    }

    /** Whether the value bound by a store is (or yields) an Arena alloc. */
    private function bindsArenaValue(Node $v): bool
    {
        if ($v->allocKind === \Compile\Mir\AllocationKind::ARENA) { return true; }
        if ($v->kind === Node::KIND_TERNARY) {
            $t = $this->castTernary($v);
            if ($t->then !== null && $this->bindsArenaValue($t->then)) { return true; }
            return $this->bindsArenaValue($t->else_);
        }
        if ($v->kind === Node::KIND_NULLCOALESCE) {
            $nc = $this->castNullCoalesce($v);
            return $this->bindsArenaValue($nc->left) || $this->bindsArenaValue($nc->right);
        }
        return false;
    }

    /**
     * Emit the pre-loop arena position save. The saved (cur, used) are
     * loop-invariant SSA values — computed once before the loop, they
     * dominate the loop header, so no alloca is needed (an alloca here
     * would re-run and grow the stack each outer iteration of a nest).
     */
    private function emitArenaSave(): string
    {
        $this->needsArena = true;
        $this->needsArenaReset = true;
        $cr = $this->allocSsa();
        $ur = $this->allocSsa();
        $this->arenaSaveCurReg = $cr;
        $this->arenaSaveUsedReg = $ur;
        $out  = '  ' . $cr . " = load ptr, ptr @__mir_arena_cur\n";
        $out .= '  ' . $ur . " = call i64 @__mir_arena_used()\n";
        return $out;
    }

    /** Emit a reset to the saved arena position (read immediately after save). */
    private function emitArenaReset(): string
    {
        return '  call void @__mir_arena_restore(ptr ' . $this->arenaSaveCurReg
            . ', i64 ' . $this->arenaSaveUsedReg . ")\n";
    }

    private function emitWhile(Node $n): string
    {
        $w = $this->castWhile($n);
        $condLabel = $this->allocLabel('loop.cond');
        $bodyLabel = $this->allocLabel('loop.body');
        $endLabel  = $this->allocLabel('loop.end');
        $savedBreak = $this->breakLabel;
        $savedCont  = $this->continueLabel;
        $this->breakLabel = $endLabel;
        $this->continueLabel = $condLabel;
        $this->breakStack[] = $endLabel;
        $this->continueStack[] = $condLabel;

        $reset = $this->loopArenaResettable($w->cond, $w->body, null);
        $out = '';
        if ($reset) { $out .= $this->emitArenaSave(); }
        $out .= '  br label %' . $condLabel . "\n";
        $out .= $condLabel . ":\n";
        if ($reset) { $out .= $this->emitArenaReset(); }
        $out .= $this->emitCondVal($w->cond);
        $cond = $this->lastValue;
        $condBit = $this->allocSsa();
        $out .= '  ' . $condBit . ' = icmp ne i64 ' . $cond . ", 0\n";
        $out .= '  br i1 ' . $condBit . ', label %' . $bodyLabel . ', label %' . $endLabel . "\n";
        $out .= $bodyLabel . ":\n";
        $out .= $this->emitNode($w->body);
        $out .= '  br label %' . $condLabel . "\n";
        $out .= $endLabel . ":\n";

        \array_pop($this->breakStack);
        \array_pop($this->continueStack);
        $this->breakLabel = $savedBreak;
        $this->continueLabel = $savedCont;
        return $out;
    }

    private function emitFor(Node $n): string
    {
        $f = $this->castFor($n);
        $condLabel = $this->allocLabel('for.cond');
        $bodyLabel = $this->allocLabel('for.body');
        $stepLabel = $this->allocLabel('for.step');
        $endLabel  = $this->allocLabel('for.end');
        $savedBreak = $this->breakLabel;
        $savedCont  = $this->continueLabel;
        // `continue` runs the step before re-testing the condition.
        $this->breakLabel = $endLabel;
        $this->continueLabel = $stepLabel;
        $this->breakStack[] = $endLabel;
        $this->continueStack[] = $stepLabel;

        $reset = $this->loopArenaResettable($f->cond, $f->body, $f->step);
        $out = '';
        if ($f->init !== null) { $out .= $this->emitNode($f->init); }
        if ($reset) { $out .= $this->emitArenaSave(); }
        $out .= '  br label %' . $condLabel . "\n";
        $out .= $condLabel . ":\n";
        if ($reset) { $out .= $this->emitArenaReset(); }
        if ($f->cond !== null) {
            $out .= $this->emitCondVal($f->cond);
            $cond = $this->lastValue;
            $condBit = $this->allocSsa();
            $out .= '  ' . $condBit . ' = icmp ne i64 ' . $cond . ", 0\n";
            $out .= '  br i1 ' . $condBit . ', label %' . $bodyLabel . ', label %' . $endLabel . "\n";
        } else {
            $out .= '  br label %' . $bodyLabel . "\n";
        }
        $out .= $bodyLabel . ":\n";
        $out .= $this->emitNode($f->body);
        $out .= '  br label %' . $stepLabel . "\n";
        $out .= $stepLabel . ":\n";
        if ($f->step !== null) { $out .= $this->emitNode($f->step); }
        $out .= '  br label %' . $condLabel . "\n";
        $out .= $endLabel . ":\n";

        \array_pop($this->breakStack);
        \array_pop($this->continueStack);
        $this->breakLabel = $savedBreak;
        $this->continueLabel = $savedCont;
        return $out;
    }

    private function emitDoWhile(Node $n): string
    {
        $d = $this->castDoWhile($n);
        $bodyLabel = $this->allocLabel('do.body');
        $condLabel = $this->allocLabel('do.cond');
        $endLabel  = $this->allocLabel('do.end');
        $savedBreak = $this->breakLabel;
        $savedCont  = $this->continueLabel;
        $this->breakLabel = $endLabel;
        $this->continueLabel = $condLabel;
        $this->breakStack[] = $endLabel;
        $this->continueStack[] = $condLabel;

        $reset = $this->loopArenaResettable($d->cond, $d->body, null);
        $out = '';
        if ($reset) { $out .= $this->emitArenaSave(); }
        $out .= '  br label %' . $bodyLabel . "\n";
        $out .= $bodyLabel . ":\n";
        if ($reset) { $out .= $this->emitArenaReset(); }
        $out .= $this->emitNode($d->body);
        $out .= '  br label %' . $condLabel . "\n";
        $out .= $condLabel . ":\n";
        $out .= $this->emitCondVal($d->cond);
        $cond = $this->lastValue;
        $condBit = $this->allocSsa();
        $out .= '  ' . $condBit . ' = icmp ne i64 ' . $cond . ", 0\n";
        $out .= '  br i1 ' . $condBit . ', label %' . $bodyLabel . ', label %' . $endLabel . "\n";
        $out .= $endLabel . ":\n";

        \array_pop($this->breakStack);
        \array_pop($this->continueStack);
        $this->breakLabel = $savedBreak;
        $this->continueLabel = $savedCont;
        return $out;
    }

    // ── String pool / escaping ─────────────────────────────────

    private function internString(string $s): int
    {
        if (isset($this->stringPool[$s])) { return $this->stringPool[$s]; }
        $id = \count($this->stringPool);
        $this->stringPool[$s] = $id;
        return $id;
    }

    private function llvmStringBytes(string $s): string
    {
        $out = '';
        $n = \strlen($s);
        for ($i = 0; $i < $n; $i = $i + 1) {
            // Index (`$s[$i]`), NOT substr: string indexing is binary-safe but
            // the runtime substr is C-strlen bounded, so `substr($s,$i,1)` on a
            // string with an embedded NUL truncates → bytes after the NUL emit
            // as \00 (the trim mask `\0\x0B`, any `\xNN`/binary literal).
            $code = \ord($s[$i]);
            if ($code === 92 || $code === 34 || $code < 0x20 || $code >= 0x7F) {
                $out .= '\\' . $this->hexByte($code);
                continue;
            }
            $out .= $s[$i];
        }
        return $out;
    }

    private function hexByte(int $b): string
    {
        $hi = ($b >> 4) & 0xF;
        $lo = $b & 0xF;
        return $this->hexNibble($hi) . $this->hexNibble($lo);
    }

    private function hexNibble(int $n): string
    {
        if ($n < 10) { return (string)$n; }
        if ($n === 10) { return 'A'; }
        if ($n === 11) { return 'B'; }
        if ($n === 12) { return 'C'; }
        if ($n === 13) { return 'D'; }
        if ($n === 14) { return 'E'; }
        return 'F';
    }

    /**
     * Trailing `, i64 <hash>, i64 <haveHash>` for a string-key array accessor.
     * A LITERAL key gets its FNV-1a folded at compile time (haveHash=1) so the
     * runtime skips re-hashing; any other key passes (0, 0) → compute at runtime.
     */
    private function litKeyHashArgs(Node $key): string
    {
        if ($key->kind === Node::KIND_STRING_CONST) {
            $h = $this->fnvHash64($this->castStringConst($key)->value);
            return ', i64 ' . (string)$h . ', i64 1';
        }
        return ', i64 0, i64 0';
    }

    /**
     * FNV-1a 64-bit over the bytes — MUST match __mir_array_hash_str exactly
     * (offset basis 0xCBF29CE484222325, prime 0x100000001B3, wrapping mul over
     * the len bytes). PHP's `*` overflows to float, so the multiply goes through
     * {@see mulmod64} (16-bit limb schoolbook) — exact under BOTH the Zend
     * bootstrap and the native self-build, which native i64 `mul` would also give.
     */
    private function fnvHash64(string $s): int
    {
        $h = -3750763034362895579; // 0xCBF29CE484222325 as signed i64
        $n = \strlen($s);
        for ($i = 0; $i < $n; $i = $i + 1) {
            $h = $h ^ \ord($s[$i]);
            $h = $this->mulmod64($h, 1099511628211);
        }
        return $h;
    }

    /** (a * b) mod 2^64, exact (no float overflow) — 16-bit limb multiply. */
    private function mulmod64(int $a, int $b): int
    {
        $a0 = $a & 0xFFFF; $a1 = ($a >> 16) & 0xFFFF; $a2 = ($a >> 32) & 0xFFFF; $a3 = ($a >> 48) & 0xFFFF;
        $b0 = $b & 0xFFFF; $b1 = ($b >> 16) & 0xFFFF; $b2 = ($b >> 32) & 0xFFFF; $b3 = ($b >> 48) & 0xFFFF;
        $c0 = $a0 * $b0;
        $c1 = $a0 * $b1 + $a1 * $b0;
        $c2 = $a0 * $b2 + $a1 * $b1 + $a2 * $b0;
        $c3 = $a0 * $b3 + $a1 * $b2 + $a2 * $b1 + $a3 * $b0;
        $r0 = $c0 & 0xFFFF;
        $k1 = ($c0 >> 16) + $c1; $r1 = $k1 & 0xFFFF;
        $k2 = ($k1 >> 16) + $c2; $r2 = $k2 & 0xFFFF;
        $k3 = ($k2 >> 16) + $c3; $r3 = $k3 & 0xFFFF;
        $low = $r0 | ($r1 << 16) | ($r2 << 32);   // < 2^48
        $hiLow = ($r3 & 0x7FFF) << 48;            // < 2^63 (no overflow)
        $bit63 = (($r3 >> 15) & 1) << 63;         // 0 or PHP_INT_MIN
        return $low | $hiLow | $bit63;
    }

    // ── Arrays (unified PhpArray, docs/16) ─────────────────────

    private function emitArrayLit(Node $n): string
    {
        $this->vecAllocArena = false;
        return $this->emitArrayLitUnified($this->castArrayLit($n));
    }

    private function emitArrayAccess(Node $n): string
    {
        $aa = $this->castArrayAccess($n);
        // `$s[$i]` on a string → fresh 1-char NUL-terminated string.
        if ($aa->array->type->kind === Type::KIND_STRING) {
            $out = $this->emitNode($aa->array);
            $out .= $this->coerceToPtr();
            $base = $this->lastValue;
            $out .= $this->emitNode($aa->index);
            $out .= $this->coerceToI64();
            $idx = $this->lastValue;
            $bytePtr = $this->allocSsa();
            $out .= '  ' . $bytePtr . ' = getelementptr inbounds i8, ptr '
                  . $base . ', i64 ' . $idx . "\n";
            $ch = $this->allocSsa();
            $out .= '  ' . $ch . ' = load i8, ptr ' . $bytePtr . "\n";
            $buf = $this->allocSsa();
            $out .= '  ' . $buf . " = call ptr @__mir_str_alloc(i64 2)\n";
            $out .= '  store i8 ' . $ch . ', ptr ' . $buf . "\n";
            $nul = $this->allocSsa();
            $out .= '  ' . $nul . ' = getelementptr inbounds i8, ptr ' . $buf . ", i64 1\n";
            $out .= '  store i8 0, ptr ' . $nul . "\n";
            $this->lastValue = $buf;
            $this->lastValueType = 'ptr';
            return $out;
        }
        // `$obj[$k]` on an ArrayAccess object → `$obj->offsetGet($k)`.
        if ($aa->array->type->kind === Type::KIND_OBJ
            && $this->classImplements($aa->array->type->class ?? '', 'ArrayAccess')) {
            $mc = new \Compile\Mir\MethodCall_($aa->array, 'offsetGet', [$aa->index], $n->type);
            return $this->emitMethodCall($mc);
        }
        return $this->emitArrayAccessUnified($n, $aa);
    }

    private function emitStoreElement(Node $n): string
    {
        $se = $this->castStoreElement($n);
        // `$obj[$k] = $v` / `$obj[] = $v` on an ArrayAccess object →
        // `$obj->offsetSet($k, $v)`. The append form `$obj[]=` already lowered
        // its index to a NullConst, so `$se->index` is the right key as-is.
        if ($se->array->type->kind === Type::KIND_OBJ
            && $this->classImplements($se->array->type->class ?? '', 'ArrayAccess')) {
            $mc = new \Compile\Mir\MethodCall_($se->array, 'offsetSet', [$se->index, $se->value], Type::void());
            return $this->emitMethodCall($mc);
        }
        return $this->emitStoreElementUnified($se);
    }

    // ── Unified PhpArray codegen (docs/16) ─────────────────────
    //
    // One path for every array literal/access/store: all ops route
    // through the `__mir_array_*` helpers, which carry the PACKED/HASHED
    // mode at runtime. There is ONE static array kind (KIND_ARRAY); the
    // vec/assoc distinction is just the key type (int vs string), a hint
    // the runtime can override by promoting on the first string key.

    private function emitArrayLitUnified(ArrayLit $al): string
    {
        $cellVals = $al->type->element !== null
            && $al->type->element->kind === Type::KIND_CELL;
        $count = \count($al->elements);
        // A non-escaping scalar array (InferAllocKind → NoRefcount → ApplyMemory
        // Mode → Arena; gated by Debug::$arenaArrays) bump-allocates in the
        // arena and is bulk-freed at frame leave — no malloc/rc/free. Its grow /
        // promote / index ops self-route via the ARRAY_TAG_ARENA tag, and its
        // retain/release bail on that tag, so nothing else in codegen changes.
        $arena = $al->allocKind === \Compile\Mir\AllocationKind::ARENA;
        $allocFn = $arena ? '__mir_array_alloc_arena' : '__mir_array_alloc';
        if ($arena) { $this->needsArena = true; $this->vecAllocArena = true; }
        $slot = $this->allocSsa();
        $out  = '  ' . $slot . " = alloca ptr\n";
        $init = $this->allocSsa();
        $out .= '  ' . $init . ' = call ptr @' . $allocFn . '(i64 ' . (string)$count . ")\n";
        $out .= '  store ptr ' . $init . ', ptr ' . $slot . "\n";
        foreach ($al->elements as $el) {
            if ($el->value->kind === Node::KIND_SPREAD) {
                $out .= $this->emitArraySpreadUnified($slot, $el->value);
                continue;
            }
            if ($el->key !== null) {
                $keyIsString = $el->key->type->kind === Type::KIND_STRING
                    || $el->key->kind === Node::KIND_STRING_CONST;
                $out .= $this->emitNode($el->key);
                $out .= $keyIsString ? $this->coerceToPtr() : $this->coerceToI64();
                $keyReg = $this->lastValue;
                $out .= $this->emitNode($el->value);
                if ($cellVals) { $out .= $this->retainCellPayload($el->value); }
                $out .= $cellVals ? $this->boxToCell($el->value->type) : $this->coerceToI64();
                $val = $this->lastValue;
                if (!$cellVals) { $out .= $this->rcRetainByType($el->value, $val, null, 2); }
                $cur = $this->allocSsa();
                $out .= '  ' . $cur . ' = load ptr, ptr ' . $slot . "\n";
                $next = $this->allocSsa();
                if ($keyIsString) {
                    $out .= '  ' . $next . ' = call ptr @__mir_array_set_str(ptr ' . $cur . ', ptr ' . $keyReg . ', i64 ' . $val . $this->litKeyHashArgs($el->key) . ")\n";
                } else {
                    $out .= '  ' . $next . ' = call ptr @__mir_array_set_int(ptr ' . $cur . ', i64 ' . $keyReg . ', i64 ' . $val . ")\n";
                }
                $out .= '  store ptr ' . $next . ', ptr ' . $slot . "\n";
            } else {
                $out .= $this->emitNode($el->value);
                if ($cellVals) { $out .= $this->retainCellPayload($el->value); }
                $out .= $cellVals ? $this->boxToCell($el->value->type) : $this->coerceToI64();
                $val = $this->lastValue;
                if (!$cellVals) { $out .= $this->rcRetainByType($el->value, $val, null, 2); }
                $cur = $this->allocSsa();
                $out .= '  ' . $cur . ' = load ptr, ptr ' . $slot . "\n";
                $next = $this->allocSsa();
                $out .= '  ' . $next . ' = call ptr @__mir_array_append(ptr ' . $cur . ', i64 ' . $val . ")\n";
                $out .= '  store ptr ' . $next . ', ptr ' . $slot . "\n";
            }
        }
        $res = $this->allocSsa();
        $out .= '  ' . $res . ' = load ptr, ptr ' . $slot . "\n";
        $this->lastValue = $res;
        $this->lastValueType = 'ptr';
        return $out;
    }

    /** Append every element of a spread source (positional) into `$slot`. */
    private function emitArraySpreadUnified(string $slot, Node $spreadNode): string
    {
        $sp = $this->castSpread($spreadNode);
        $out = $this->emitNode($sp->operand);
        $out .= $this->coerceToPtr();
        $src = $this->lastValue;
        $slen = $this->allocSsa();
        $out .= '  ' . $slen . ' = load i64, ptr ' . $src . "\n";
        $iSlot = $this->allocSsa();
        $out .= '  ' . $iSlot . " = alloca i64\n";
        $out .= '  store i64 0, ptr ' . $iSlot . "\n";
        $cond = $this->allocLabel('uspr.cond');
        $body = $this->allocLabel('uspr.body');
        $end  = $this->allocLabel('uspr.end');
        $out .= '  br label %' . $cond . "\n" . $cond . ":\n";
        $i = $this->allocSsa();
        $out .= '  ' . $i . ' = load i64, ptr ' . $iSlot . "\n";
        $c = $this->allocSsa();
        $out .= '  ' . $c . ' = icmp slt i64 ' . $i . ', ' . $slen . "\n";
        $out .= '  br i1 ' . $c . ', label %' . $body . ', label %' . $end . "\n";
        $out .= $body . ":\n";
        $ev = $this->allocSsa();
        $out .= '  ' . $ev . ' = call i64 @__mir_array_value_at(ptr ' . $src . ', i64 ' . $i . ")\n";
        $cur = $this->allocSsa();
        $out .= '  ' . $cur . ' = load ptr, ptr ' . $slot . "\n";
        $nx = $this->allocSsa();
        $out .= '  ' . $nx . ' = call ptr @__mir_array_append(ptr ' . $cur . ', i64 ' . $ev . ")\n";
        $out .= '  store ptr ' . $nx . ', ptr ' . $slot . "\n";
        $i2 = $this->allocSsa();
        $out .= '  ' . $i2 . ' = add i64 ' . $i . ", 1\n";
        $out .= '  store i64 ' . $i2 . ', ptr ' . $iSlot . "\n";
        $out .= '  br label %' . $cond . "\n" . $end . ":\n";
        return $out;
    }

    private function emitArrayAccessUnified(Node $self, ArrayAccess_ $aa): string
    {
        $out = $this->emitNode($aa->array);
        // A `mixed`/cell base (e.g. a nested value out of json_decode) carries
        // the array pointer NaN-boxed — strip the tag to the payload ptr, not
        // a raw inttoptr of the boxed bits (which faults in __mir_array_get).
        if ($aa->array->type->kind === Type::KIND_CELL) {
            $out .= $this->cellToPtr();
        } else {
            $out .= $this->coerceToPtr();
        }
        $arrPtr = $this->lastValue;
        // A `mixed`/cell index (int-OR-string at runtime) → dispatch helper.
        $keyIsCell = $aa->index->type->kind === Type::KIND_CELL;
        $keyIsString = $aa->index->type->kind === Type::KIND_STRING
            || $aa->index->kind === Node::KIND_STRING_CONST;
        $out .= $this->emitNode($aa->index);
        $out .= $keyIsString ? $this->coerceToPtr() : $this->coerceToI64();
        $key = $this->lastValue;
        $reg = $this->allocSsa();
        if ($keyIsCell) {
            $this->needsCellKey = true;
            $out .= '  ' . $reg . ' = call i64 @__mir_array_get_cell(ptr ' . $arrPtr . ', i64 ' . $key . ")\n";
        } elseif ($keyIsString) {
            $out .= '  ' . $reg . ' = call i64 @__mir_array_get_str(ptr ' . $arrPtr . ', ptr ' . $key . $this->litKeyHashArgs($aa->index) . ")\n";
        } else {
            $out .= '  ' . $reg . ' = call i64 @__mir_array_get_int(ptr ' . $arrPtr . ', i64 ' . $key . ")\n";
        }
        $this->lastValue = $reg;
        $this->lastValueType = 'i64';
        if ($self->type->kind === Type::KIND_FLOAT) {
            $regF = $this->allocSsa();
            $out .= '  ' . $regF . ' = bitcast i64 ' . $reg . " to double\n";
            $this->lastValue = $regF;
            $this->lastValueType = 'double';
        }
        return $out;
    }

    private function emitStoreElementUnified(StoreElement $se): string
    {
        // A `mixed`/cell base (mixed property / param holding an array) carries
        // the array pointer NaN-boxed — strip the tag to the payload ptr, and
        // re-box on write-back so the slot stays a valid array cell (mirrors the
        // read path in emitArrayAccessUnified). Without this the store inttoptr's
        // the boxed bits → SIGSEGV in __mir_array_append/set.
        $baseCell = $se->array->type->kind === Type::KIND_CELL;
        $out = $this->emitNode($se->array);
        $out .= $baseCell ? $this->cellToPtr() : $this->coerceToPtr();
        $arrPtr = $this->lastValue;
        // COW shared buffers (PHP array value semantics) before mutating.
        if ($se->array->kind === Node::KIND_LOAD_LOCAL
            || $se->array->kind === Node::KIND_PROPERTY_ACCESS) {
            $cow = $this->allocSsa();
            $out .= '  ' . $cow . ' = call ptr @__mir_array_cow(ptr ' . $arrPtr . ")\n";
            $out .= $this->vecWriteBack($se->array, $cow, $baseCell);
            $arrPtr = $cow;
        } elseif ($se->array->kind === Node::KIND_ARRAY_ACCESS) {
            $cow = $this->allocSsa();
            $out .= '  ' . $cow . ' = call ptr @__mir_array_cow(ptr ' . $arrPtr . ")\n";
            $arrPtr = $cow;
        }
        $isAppend = $se->index->kind === Node::KIND_NULL_CONST;
        $keyIsCell = !$isAppend && $se->index->type->kind === Type::KIND_CELL;
        $keyIsString = !$isAppend && !$keyIsCell
            && ($se->index->type->kind === Type::KIND_STRING
                || $se->index->kind === Node::KIND_STRING_CONST);
        // A cell-element container (a KIND_CELL base, OR an assoc/vec whose
        // ELEMENT type is a cell) stores every value NaN-boxed — like the
        // literal path's $cellVals. Without it a read-back / var_dump misreads
        // a raw i64 as a tagged cell (`$e["s"]="hi"; $e["a"]=[1,2]`).
        $elemCell = !$baseCell
            && ($et = $se->array->type->element) !== null
            && $et->kind === Type::KIND_CELL;
        $boxVal = $baseCell || $elemCell;
        $next = $this->allocSsa();
        if ($isAppend) {
            $out .= $this->emitNode($se->value);
            if ($boxVal) { $out .= $this->boxToCell($se->value->type); $val = $this->lastValue; }
            else { $out .= $this->coerceToI64(); $val = $this->lastValue; $out .= $this->rcRetainByType($se->value, $val, null, 3); }
            $out .= '  ' . $next . ' = call ptr @__mir_array_append(ptr ' . $arrPtr . ', i64 ' . $val . ")\n";
        } elseif ($keyIsCell) {
            $this->needsCellKey = true;
            $out .= $this->emitNode($se->index);
            $out .= $this->coerceToI64();
            $key = $this->lastValue;
            $out .= $this->emitNode($se->value);
            if ($boxVal) { $out .= $this->boxToCell($se->value->type); $val = $this->lastValue; }
            else { $out .= $this->coerceToI64(); $val = $this->lastValue; $out .= $this->rcRetainByType($se->value, $val, null, 3); }
            $out .= '  ' . $next . ' = call ptr @__mir_array_set_cell(ptr ' . $arrPtr . ', i64 ' . $key . ', i64 ' . $val . ")\n";
        } elseif ($keyIsString) {
            $out .= $this->emitNode($se->index);
            $out .= $this->coerceToPtr();
            $key = $this->lastValue;
            $out .= $this->emitNode($se->value);
            if ($boxVal) { $out .= $this->boxToCell($se->value->type); $val = $this->lastValue; }
            else { $out .= $this->coerceToI64(); $val = $this->lastValue; $out .= $this->rcRetainByType($se->value, $val, null, 3); }
            $out .= '  ' . $next . ' = call ptr @__mir_array_set_str(ptr ' . $arrPtr . ', ptr ' . $key . ', i64 ' . $val . $this->litKeyHashArgs($se->index) . ")\n";
        } else {
            $out .= $this->emitNode($se->index);
            $out .= $this->coerceToI64();
            $idx = $this->lastValue;
            $out .= $this->emitNode($se->value);
            if ($boxVal) { $out .= $this->boxToCell($se->value->type); $val = $this->lastValue; }
            else { $out .= $this->coerceToI64(); $val = $this->lastValue; $out .= $this->rcRetainByType($se->value, $val, null, 3); }
            $out .= '  ' . $next . ' = call ptr @__mir_array_set_int(ptr ' . $arrPtr . ', i64 ' . $idx . ', i64 ' . $val . ")\n";
        }
        $out .= $this->vecWriteBack($se->array, $next, $baseCell);
        $this->lastValue = $val;
        $this->lastValueType = 'i64';
        return $out;
    }


    /**
     * Materialize `$this->lastValue` as a `ptr` — used when an
     * inttoptr is needed (e.g. a vec ptr came in as the i64 slot
     * payload).
     */
    private function coerceToPtr(): string
    {
        if ($this->lastValueType === 'ptr') { return ''; }
        if ($this->lastValueType === 'i64') {
            $reg = $this->allocSsa();
            $out = '  ' . $reg . ' = inttoptr i64 ' . $this->lastValue . " to ptr\n";
            $this->lastValue = $reg;
            $this->lastValueType = 'ptr';
            return $out;
        }
        return '';
    }

    /**
     * Render a PHP float as an LLVM IR literal. LLVM accepts both
     * decimal (`1.5`, `1.500000e+00`) and bit-exact hex
     * (`0x3FF8000000000000`) forms; decimal is human-readable and
     * round-trips for the small set of literals current MIR
     * surfaces. Forces a decimal point so LLVM doesn't parse an
     * integer-looking value as `i64`.
     */
    private function formatFloat(float $v): string
    {
        // INF / NAN have no decimal form LLVM accepts — emit the exact IEEE-754
        // double bit pattern in hex (LLVM `double 0x...`). Detected with plain
        // fcmp (NaN != itself; |v| past the finite max is infinite) so this
        // works in the self-hosted compiler too (no is_nan/is_infinite builtin).
        if (!($v == $v)) { return '0x7FF8000000000000'; }
        // ±INF detection must NOT compare against a DBL_MAX *literal*: that
        // literal, parsed by the runtime's own strtod, can itself round to
        // INF, making `INF > DBL_MAX_literal` == `INF > INF` == false — then
        // +INF leaks through to `(string)$v` = "inf" and we emit the INVALID
        // IR token `inf.0`. A finite value satisfies `v - v == 0`; ±INF gives
        // `INF - INF == NaN != 0`. Threshold-free, so it always fires.
        $d = $v - $v;
        if (!($d == 0.0)) {
            return ($v > 0.0) ? '0x7FF0000000000000' : '0xFFF0000000000000';
        }
        // `(string)$v` uses PHP `precision` (14 sig figs) → a literal like
        // 0.30000000000000004 would round to "0.3" and the emitted constant
        // (and any `=== 0.300…04`) would be WRONG. 17 significant digits is the
        // round-trip width for binary64, so `%.17g` reproduces the exact double
        // (LLVM parses the decimal to the nearest double = the original bits).
        $s = \sprintf('%.17g', $v);
        // Belt-and-suspenders: if the float→string ever yields a non-numeric
        // word ("inf"/"nan"/"INF"), never let it reach the IR — map to the
        // IEEE-754 bit pattern by sign (NaN already handled above).
        if ($s === 'inf' || $s === 'INF' || $s === 'nan' || $s === 'NAN') {
            return ($v < 0.0) ? '0xFFF0000000000000' : '0x7FF0000000000000';
        }
        // LLVM requires a decimal point in the MANTISSA. Bare "5" is parsed as
        // i64 (rejecting the surrounding `bitcast double`); an exponent form
        // with no point — "1e+308" (manticore's float→string) — is likewise
        // rejected. So locate the exponent and the dot manually (self-host
        // strpos returns -1 on miss, so scan), and inject ".0" before the
        // exponent (or at the end) when the mantissa has no point.
        $n = \strlen($s);
        $ePos = -1;
        $dotPos = -1;
        for ($i = 0; $i < $n; $i = $i + 1) {
            $c = \substr($s, $i, 1);
            if ($c === '.') { $dotPos = $i; }
            if ($c === 'e' || $c === 'E') { $ePos = $i; break; }
        }
        if ($dotPos >= 0) { return $s; }            // already has a point
        if ($ePos < 0) { return $s . '.0'; }        // "5" -> "5.0"
        return \substr($s, 0, $ePos) . '.0' . \substr($s, $ePos); // "1e+308" -> "1.0e+308"
    }

    // ── SSA / label minting ────────────────────────────────────

    private function allocSsa(): string
    {
        $id = $this->nextId;
        $this->nextId = $this->nextId + 1;
        return '%r' . (string)$id;
    }

    private function allocLabel(string $hint): string
    {
        $id = $this->nextLabel;
        $this->nextLabel = $this->nextLabel + 1;
        return $hint . '.' . (string)$id;
    }

    /** Stable LLVM block label for a user `goto` label name — allocated once per
     *  function so a `goto L` and the `L:` label resolve to the SAME block. */
    private function userLabel(string $name): string
    {
        if (!isset($this->userLabels[$name])) {
            $this->userLabels[$name] = $this->allocLabel('user.' . $name);
        }
        return $this->userLabels[$name];
    }

    private function castGoto(Node $n): \Compile\Mir\Goto_ { return $n; }
    private function castLabel(Node $n): \Compile\Mir\Label_ { return $n; }

    // ── Typed-cast helpers ─────────────────────────────────────

    private function castIntConst(IntConst $n): IntConst { return $n; }
    private function castFloatConst(FloatConst $n): FloatConst { return $n; }
    private function castDiv(Div $n): Div { return $n; }
    private function castBoolConst(BoolConst $n): BoolConst { return $n; }
    private function castStringConst(StringConst $n): StringConst { return $n; }
    private function castLoadLocal(LoadLocal $n): LoadLocal { return $n; }
    private function castStoreLocal(StoreLocal $n): StoreLocal { return $n; }
    private function castNeg(Neg $n): Neg { return $n; }
    private function castNot(Not_ $n): Not_ { return $n; }
    private function castConcat(Concat $n): Concat { return $n; }
    private function castCmp(Cmp $n): Cmp { return $n; }
    private function asBoolConst(Node $n): BoolConst { return $n; }
    private function castEcho(Echo_ $n): Echo_ { return $n; }
    private function castReturn(Return_ $n): Return_ { return $n; }
    private function castCall(Call $n): Call { return $n; }
    private function castArrayLit(ArrayLit $n): ArrayLit { return $n; }
    private function castSpread(Node $n): Spread_ { return $n; }
    private function castArrayAccess(ArrayAccess_ $n): ArrayAccess_ { return $n; }
    private function castStoreElement(StoreElement $n): StoreElement { return $n; }
    private function castNewObj(NewObj $n): NewObj { return $n; }
    private function castClone(Clone_ $n): Clone_ { return $n; }
    private function castPropertyAccess(PropertyAccess_ $n): PropertyAccess_ { return $n; }
    private function castStoreProperty(StoreProperty $n): StoreProperty { return $n; }
    private function castMethodCall(MethodCall_ $n): MethodCall_ { return $n; }
    private function castStaticCall(StaticCall_ $n): StaticCall_ { return $n; }
    /** `break N` target — read the break stack directly (no array param;
     *  self-host mishandles indexing an array passed by value). */
    private function breakTargetFor(int $level): string
    {
        $n = \count($this->breakStack);
        if ($n === 0) { return 'unreachable_no_loop'; }
        $idx = $n - $level;
        if ($idx < 0) { $idx = 0; }
        return $this->breakStack[$idx];
    }

    private function continueTargetFor(int $level): string
    {
        $n = \count($this->continueStack);
        if ($n === 0) { return 'unreachable_no_loop'; }
        $idx = $n - $level;
        if ($idx < 0) { $idx = 0; }
        return $this->continueStack[$idx];
    }

    private function castBreak(Break_ $n): Break_ { return $n; }
    private function castContinue(Continue_ $n): Continue_ { return $n; }
    private function castIf(If_ $n): If_ { return $n; }
    private function castWhile(While_ $n): While_ { return $n; }
    private function castForeach(Foreach_ $n): Foreach_ { return $n; }
    private function castFor(For_ $n): For_ { return $n; }
    private function castDoWhile(DoWhile_ $n): DoWhile_ { return $n; }
    private function castCast(Cast $n): Cast { return $n; }
    private function castInstanceof(Instanceof_ $n): Instanceof_ { return $n; }
    private function castNullCoalesce(NullCoalesce_ $n): NullCoalesce_ { return $n; }
    private function castClosure(Closure_ $n): Closure_ { return $n; }
    private function castInvoke(Invoke_ $n): Invoke_ { return $n; }
    private function castIncDec(IncDec $n): IncDec { return $n; }
    private function castStaticProp(StaticProp_ $n): StaticProp_ { return $n; }
    private function castStoreStaticProp(StoreStaticProp_ $n): StoreStaticProp_ { return $n; }
    private function castStaticLocalDecl(StaticLocalDecl_ $n): StaticLocalDecl_ { return $n; }
    private function castTernary(Ternary $n): Ternary { return $n; }
    private function castSwitch(Switch_ $n): Switch_ { return $n; }
    private function castMatch(Match_ $n): Match_ { return $n; }
    /** Read a node's type kind through a typed param: a match cond comes
     *  from `foreach ($arm->conds as $c)` where `conds` is `?array` — the
     *  loop var is untyped, so an inline `$c->type->kind` resolves the wrong
     *  field offset (self-host) and reads garbage. Routing through `Node $c`
     *  fixes the offset. */
    private function nodeTypeKind(Node $c): string { return $c->type->kind; }
    private function castBlock(Block $n): Block { return $n; }

    private function binLeft(Node $n): Node
    {
        $k = $n->kind;
        if ($k === Node::KIND_ADD) { return $this->castAdd($n)->left; }
        if ($k === Node::KIND_SUB) { return $this->castSub($n)->left; }
        if ($k === Node::KIND_MUL) { return $this->castMul($n)->left; }
        if ($k === Node::KIND_DIV) { return $this->castDiv($n)->left; }
        if ($k === Node::KIND_MOD) { return $this->castMod($n)->left; }
        return $this->castCmp($n)->left;
    }

    private function binRight(Node $n): Node
    {
        $k = $n->kind;
        if ($k === Node::KIND_ADD) { return $this->castAdd($n)->right; }
        if ($k === Node::KIND_SUB) { return $this->castSub($n)->right; }
        if ($k === Node::KIND_MUL) { return $this->castMul($n)->right; }
        if ($k === Node::KIND_DIV) { return $this->castDiv($n)->right; }
        if ($k === Node::KIND_MOD) { return $this->castMod($n)->right; }
        return $this->castCmp($n)->right;
    }

    private function castAdd(Add $n): Add { return $n; }
    private function castSub(Sub $n): Sub { return $n; }
    private function castMul(Mul $n): Mul { return $n; }
    private function castMod(Mod $n): Mod { return $n; }
}
