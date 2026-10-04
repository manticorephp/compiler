<?php

namespace Compile\Mir\Passes;

use Compile\Mir\Add;
use Compile\Mir\Block;
use Compile\Mir\ArrayAccess_;
use Compile\Mir\ArrayLit;
use Compile\Mir\MethodMeta;
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
use Compile\Mir\RuntimeFeatures;
use Compile\Mir\StringPool;
use Compile\Mir\SsaBuilder;
use Compile\Mir\GeneratorContext;
use Compile\Mir\ControlFlow;
use Compile\Mir\FunctionEmitFrame;
use Compile\Mir\FunctionSignatures;
use Compile\Mir\ArenaContext;
use Compile\Mir\LocalSlots;
use Compile\Mir\RuntimeLibrary;
use Compile\Mir\EmitVisitor;
use Compile\Mir\BitOp;
use Compile\Mir\BitNot_;
use Compile\Mir\MemoryOp_;
use Compile\Mir\Yield_;
use Compile\Mir\Goto_;
use Compile\Mir\Label_;
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
final class EmitLlvm implements EmitVisitor
{
    use EmitLlvmVisit;
    use EmitLlvmExpr;
    use EmitLlvmControl;
    use EmitLlvmLocals;
    use EmitLlvmCalls;
    use EmitLlvmMemory;
    use EmitLlvmArrays;
    use EmitLlvmGenerator;
    use EmitLlvmModule;
    use EmitLlvmRuntime;
    use EmitLlvmBuiltins;
    use EmitLlvmExceptions;
    use EmitLlvmObjects;
    use EmitLlvmFiber;
    use EmitLlvmCellGuard;

    public function name(): string { return 'emit-llvm'; }

    /**
     * Emit aggregate compiler-root telemetry without walking target values.
     * The normal compiler path performs no extra counts or string scans.
     */
    /** Clear only pure per-emission memoization tables; helper bodies/registries stay live. */
    private function compactEmissionCaches(): void
    {
        if (!\Compile\Debug::$compactCaches) { return; }
        $this->mangleCache = [];
        $this->classImplementsCache = [];
        $this->classImplementsIfaceCache = [];
        $this->classIsACache = [];
        $this->selfDescendantsCache = [];
        $this->fixedPropertyHoldersCache = [];
        $this->bagClassNamesCache = [];
        $this->magicPropertyHoldersCache = [];
    }

    private function rootSnapshot(string $phase, \Compile\Mir\Module $module, bool $withBytes = false, int $stagedBytes = 0): void
    {
        if (!\Compile\Debug::$rootTrace) { return; }
        $cacheEntries = \count($this->methodHoldersIdx)
            + \count($this->mangleCache)
            + \count($this->classImplementsCache)
            + \count($this->classImplementsIfaceCache)
            + \count($this->classIsACache)
            + \count($this->selfDescendantsCache)
            + \count($this->fixedPropertyHoldersCache)
            + \count($this->bagClassNamesCache)
            + \count($this->magicPropertyHoldersCache);
        $helperBytes = 0;
        if ($withBytes) {
            foreach ($this->propertyReadHelpers as $text) { $helperBytes += \strlen($text); }
            foreach ($this->dynamicMethodHelpers as $text) { $helperBytes += \strlen($text); }
        }
        \Compile\Stats::rootLine('roots phase=' . $phase
            . ' module_fns=' . (string)\count($module->functions)
            . ' module_classes=' . (string)\count($module->classes)
            . ' module_globals=' . (string)\count($module->globalNames)
            . ' staged_bytes=' . (string)$stagedBytes
            . ' emitter_classes=' . (string)\count($this->classes)
            . ' defined_fns=' . (string)\count($this->definedFns)
            . ' caches=' . (string)$cacheEntries
            . ' resolve=' . (string)\count($this->methodHoldersIdx)
            . ' mangle=' . (string)\count($this->mangleCache)
            . ' impl=' . (string)\count($this->classImplementsCache)
            . ' iface=' . (string)\count($this->classImplementsIfaceCache)
            . ' isa=' . (string)\count($this->classIsACache)
            . ' desc=' . (string)\count($this->selfDescendantsCache)
            . ' fixed=' . (string)\count($this->fixedPropertyHoldersCache)
            . ' bag=' . (string)\count($this->bagClassNamesCache)
            . ' magic=' . (string)\count($this->magicPropertyHoldersCache)
            . ' prop_helpers=' . (string)\count($this->propertyReadHelpers)
            . ' dyn_helpers=' . (string)\count($this->dynamicMethodHelpers)
            . ' closures=' . (string)\count($this->closureCaptures)
            . ' sigs=' . (string)\count($this->sigs->refParams)
            . ' helper_bytes=' . (string)$helperBytes);
    }

    /** Interned string-literal pool (fresh each {@see emit}). */
    private ?StringPool $pool = null;

    /** Per-function SSA register + label allocator (fresh each {@see emit}). */
    private ?SsaBuilder $ssa = null;
    private int $switchCounter = 0;
    /** Sequence for private per-function raw text sink files. */
    private int $functionTextCounter = 0;

    /** {@see EmitLlvmObjects::methodHolders}: method => [class => resolved holder].
     *  @var array<string, array<string, string>> */
    private array $methodHoldersIdx = [];

    /** Class-table size the index was built against; a change drops it. */
    private int $methodHoldersClassCount = -1;

    /** @var array<string, string> deterministic PHP-name → LLVM-name cache */
    private array $mangleCache = [];
    /** @var array<string, bool> */
    private array $classImplementsCache = [];
    /** @var array<string, bool> */
    private array $classImplementsIfaceCache = [];
    /** @var array<string, bool> */
    private array $classIsACache = [];
    /** @var array<string, string[]> */
    private array $selfDescendantsCache = [];
    /** @var array<string, ClassDef[]> */
    private array $fixedPropertyHoldersCache = [];
    /** @var array<string, string[]> */
    private array $bagClassNamesCache = [];
    /** @var array<string, array<string, string>> */
    private array $magicPropertyHoldersCache = [];
    /** @var array<string, string> helper symbol → emitted LLVM body */
    private array $propertyReadHelpers = [];
    /** @var array<string, string> erased dynamic-method helper bodies */
    private array $dynamicMethodHelpers = [];
    /** Prevent recursive uniform-ABI selection while emitting an extracted fallback. */
    private bool $dynamicMethodAbiDisabled = false;

    // Out-slot for {@see cellTagIr}: the SSA reg holding the computed cell tag.
    private string $cellTagReg = '';

    // Out-slot for {@see plausiblePtrIr}: the i1 reg guarding a `ptr-8` probe.
    private string $plausiblePtrReg = '';

    // Out-slot for {@see arrayPtrOrEmptyIr}: array ptr, or the empty zero word.
    private string $arrayPtrReg = '';

    // Out-slot for {@see emitStoreElemValue} / {@see emitArrayLitValue}: the i64
    // reg holding the element value word. A field, not a by-ref out-param —
    // that pattern miscompiles under self-host ({@see cellTagIr}).
    private string $elemValReg = '';
    /** The RAW value word a boxing element store took in, with its LLVM repr —
     *  what the store EXPRESSION yields ({@see EmitLlvmArrays::emitStoreElemValue}).
     *  '' when the store did not box. */
    private string $elemRawReg = '';
    private string $elemRawType = 'i64';

    /** The `i1` saying the last element store wrote THROUGH a reference cell
     *  ({@see EmitLlvmArrays::emitElemWriteThrough}), '' when that path is not
     *  live. A store that wrote through displaced nothing at the SLOT, so the
     *  slot drop must skip it. */
    private string $elemWroteThroughRef = '';
    /** The i64 word of the last {@see EmitLlvmExpr::unionOperandPtr} operand, for its temp release. */
    private string $unionTempWord = '';

    // Out-slot for {@see magicMatchIr}: the IR computing the `ptr-8` magic test.
    private string $magicMatchOut = '';

    // Out-slot for {@see genFrameProbeIr}: i1, "this iterator is a generator frame".
    private string $genFrameReg = '';

    // Out-slot for {@see objectProbeIr}: i1, "this erased word is an object".
    private string $objectProbeReg = '';

    /** break/continue/finally targets of the current function (fresh each {@see emit}). */
    private ?ControlFlow $cf = null;

    /** Identity + ABI of the function being emitted (fresh each {@see emit}). */
    private ?FunctionEmitFrame $frame = null;

    /** Call-site signature registry for the module (fresh each {@see emit}). */
    private ?FunctionSignatures $sigs = null;

    /** The ownership classifier ({@see \Compile\Mir\Ownership}; fresh each {@see emit}). */
    private ?\Compile\Mir\Ownership $own = null;

    /** Arena-allocation state of the current function (fresh each {@see emit}). */
    private ?ArenaContext $arena = null;

    /** Where each local of the current function lives (fresh each {@see emit}). */
    private ?LocalSlots $locals = null;

    /** The fixed LLVM text of the runtime helpers (stateless). */
    private ?RuntimeLibrary $lib = null;

    /** @var array<string, \Compile\Mir\ClassDef> */
    private array $classes = [];

    /** Classes needing reflection metadata ({@see ReflectAnalysis}).
     *  @var array<string, bool> */
    private array $reflectNames = [];

    /** Every class needs metadata — the analysis could not resolve some name,
     *  or never ran. Defaults true so a path that skips the pass stays
     *  correct-but-fat rather than silently answering "class not found". */
    private bool $reflectAll = true;

    /** {@see \Compile\Mir\Module::$hasClassAlias} */
    private bool $hasClassAlias = false;

    /** Compiler-owned lightweight method tables for erased dynamic calls. */
    private bool $dynamicMethodMeta = false;
    /** @var array<string, bool> {@see Module::$callableArrayMethods} */
    private array $callableArrayMethods = [];

    /** `#[TypeDef]` value types. Never in {@see $classes}: nothing is emitted for
     *  them — no descriptor, no drop fn. Consulted only to turn `$byte->value` into the
     *  receiver itself and `$byte->method()` into a direct call.
     *  @var array<string, \Compile\Mir\ClassDef> */
    private array $typeDefs = [];

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
    /** @var array<string, true> {@see Module::$internalInterfaceNames} */
    private array $internalInterfaceNames = [];

    /** @var array<string, true> trait names (trait_exists fold) */
    private array $traitNames = [];

    /** Arg-list suffix produced by the most recent {@see emitDefaultArgPad}. */
    private string $lastPadArgs = '';

    /** Post-call IR releasing the by-ref slots that pad backed ({@see omittedRefSlotDrop}). */
    private string $lastPadDrops = '';

    /** Post-call IR releasing the slot of the latest {@see emitRefValueSlot}. */
    private string $lastRefSlotDrop = '';

    // ── generator state (set while emitting a `$resume` function) ──
    /** Per-function generator emit state (fresh each {@see emit}). */
    private ?GeneratorContext $gen = null;

    /** Program source path (exception file() / trace frames). */
    private string $sourceFile = '';
    /** The error/shutdown prelude is compiled in: main() gets the atexit
     *  trampoline and the uncaught path consults set_exception_handler. */
    private bool $needsErrorHandlers = false;
    /** prelude/ob.php is compiled in: main() gets the atexit drain. */
    private bool $needsOb = false;
    /** This module generated `__mir_obj_to_str`, so a cell→string coercion may
     *  branch on the object tag and call it. {@see coerceCellToStr} */
    private bool $hasObjToStr = false;

    /** True while emitting a `$r = &fn()` bind (suppress call-result deref). */
    private bool $rawRefCall = false;

    /** Scratch regs threaded out of {@see emitBagPtr}. */
    private string $bagSlotReg = '';
    private string $bagPtrReg = '';

    /** @var array<string, int> closure fn name → capture count */
    private array $closureCaptures = [];

    /** Resolved source path → the global slot holding that file's top-level
     *  `return` value ({@see Module::$includeSlots}). Read by the
     *  `require`/`include` builtin. @var array<string, string> */
    private array $includeSlots = [];
    /**
     * String literals of `$n` that name a builtin twin.
     * @param array<string, int> $twins
     * @param array<string, bool> $out
     */
    private function collectTwinNames(Node $n, array $twins, array &$out): void
    {
        if ($n->kind === Node::KIND_STRING_CONST && $n instanceof \Compile\Mir\StringConst) {
            $v = \strtolower(\ltrim($n->value, '\\'));
            if (isset($twins[$v])) { $out[$v] = true; }
            return;
        }
        foreach (\Compile\Mir\Walk::children($n) as $c) { $this->collectTwinNames($c, $twins, $out); }
    }

    /** @var array<string, int> {@see Module::$builtinTwinReq} */
    private array $builtinTwinReq = [];
    /** @var array<string, int> */
    private array $builtinTwinTot = [];
    /** @var array<string, bool> */
    private array $callableShims = [];
    /** @var array<string, string> */
    private array $builtinTwinRet = [];

    /** Names a dynamic `function_exists()` answers true for ({@see Module::$knownFnNames}).
     *  @var string[] */
    private array $knownFnNames = [];

    /** @var array<string, string[]> closure fn name → per-capture rc flavor,
     *  registered by the LITERAL and drained into the generated
     *  `__mc_drop` / `__mc_retain` pair after the function loop. */
    private array $closureDrops = [];
    /** @var array<string,bool> closure fn name → has a `$this` slot (slot 1). */
    private array $closureHasThis = [];

    /** Out-param for {@see emitLoadClassId} — the class_id SSA reg (avoids a
     *  list-destructure return, which self-host doesn't support). */
    private string $classIdReg = '';
    /** This module defines `@main` (the user program, not the stdlib library). */
    private bool $moduleHasMain = false;
    /** @var array<string, string> libc symbol → declare line (builtins) */
    private array $libcExtra = [];
    /**
     * C symbol → the FFI binding that declared it, so a SECOND binding of the
     * same symbol with a different C signature can be reported instead of
     * silently losing to whichever wrapper was emitted first.
     * {@see EmitLlvmCalls::emitFfiWrapper}
     * @var array<string, string>
     */
    private array $ffiDeclOwner = [];

    /**
     * Native libraries this module's `#[Ffi\Library]` bindings need at link
     * time, as a name→true set. 'c' is never recorded — libc/libSystem is
     * always linked. Read by the driver at the `cc` step, and carried into the
     * module's `.sig` so a program that reaches these wrappers through a
     * prebuilt `.o` still links the library they call.
     * @var array<string, bool>
     */
    public array $ffiLibs = [];

    /**
     * C symbols this module declared `extern_weak` — from `#[Ffi\Weak]` and
     * from the errno builtin, which is why the set is collected HERE and not
     * off FunctionDef. Darwin's ld needs `-Wl,-U,_<sym>` to permit each one
     * undefined; deriving the list means it cannot drift from the bindings.
     * @var array<string, bool>
     */
    public array $weakSyms = [];
    /**
     * Free-function calls compiled into a runtime "Call to undefined function"
     * trap ({@see EmitLlvmCalls::emitCall}) — nothing in the module and nothing
     * in a linked library's `.sig` defines the symbol, so the call becomes a
     * throw instead of a link error.
     *
     * Collected because the trap is SILENT: a compiler that does not yet know a
     * name still "succeeds" at building source that uses it, and the breakage
     * surfaces a generation later (a poisoned `lib/*.o`, or a self-built
     * compiler that throws the first time it reaches the call). That is the
     * whole shape of "this needed a cold seed and nothing said so". The driver
     * reports the set, and refuses it outright for a LIBRARY target.
     *
     * @var array<string, bool> function name → true
     */
    public array $undefinedCalls = [];
    /**
     * Native FFI-boundary primitives (`manticore_rt_*`) called but not
     * PHP-defined. Declared as externs so the module assembles; the
     * tools/link_stubs.sh link-stubs them (the compiler never invokes the
     * FFI path at compile time). symbol → declare line.
     * @var array<string, string>
     */
    private array $rtExterns = [];
    /** @var array<string, bool> mangled module-fn name → defined (for extern detection) */
    private array $definedFns = [];
    /** @var array<string, bool> FFI bindings (`#[Ffi\Symbol]`): a pointer arrives as an int */
    private array $ffiFnNames = [];
    /** The call being argued is a direct call to a PHP (non-FFI) function
     *  whose declared params are its own ({@see unboxCellArg}). */
    private bool $argsRenderScalars = false;
    /** @var array<string, string> by-ref foreach value var → alloca holding 1
     *  when its latest store in the body left a CELL ({@see foreachWriteBackEncode}) */
    private array $feCellFlags = [];
    /** A mixed slot is boxing its own raw value: the box takes over the
     *  slot's count ({@see EmitLlvmBuiltins::boxArrayShallow}). */
    private bool $boxSelfMove = false;
    /** @var array<string, bool> class → every class below it reads through
     *  SplFixedArray::offsetGet ({@see EmitLlvmArrays::emitFixedArrayGet}) */
    private array $fixedArrayPlain = [];
    /** @var array<string, bool> …and whether the body emitted such a store */
    private array $feCellFlagSet = [];
    /**
     * Library build (prebuilt stdlib.o): suppress the `@main` entry point so
     * the object links cleanly alongside a user program's own `@main`. Set by
     * the `--emit-library` compile path.
     */
    public bool $emitLibrary = false;
    /** Whether staged emission defers string globals to the body writer. */
    public bool $deferStringGlobals = false;

    /**
     * Write every lazily generated property-read / dynamic-method helper body
     * through `$sink`, to a FIXPOINT.
     *
     * Emitting a helper can register another one — a property-read helper may
     * demand a dynamic-method helper, and a dynamic-method helper may demand a
     * further one. `foreach` iterates a COPY of the array, so anything
     * registered during the drain used to be dropped on the floor: the call
     * site was emitted, the definition never was, and clang refused the module
     * with `use of undefined value @manticore___mc_dyn_method0_…`. One AOT case
     * (`sapi_ctx_two_tasks`) was red on exactly that.
     *
     * The registries double as the GENERATOR'S DEDUP TABLE — `emitDynamicMethod*`
     * returns early on `isset($this->dynamicMethodHelpers[$key])`. So a key may
     * never be removed before its body is written, or the next request would
     * regenerate it and the module would carry two definitions. The body string
     * is blanked instead: the key survives as a tombstone, the text is freed.
     */
    private function drainLazyHelpers(callable $sink): void
    {
        $writtenProp = [];
        $writtenDyn = [];
        $progress = true;
        while ($progress) {
            $progress = false;
            foreach ($this->propertyReadHelpers as $name => $body) {
                if (isset($writtenProp[$name])) { continue; }
                $writtenProp[$name] = true;
                $this->propertyReadHelpers[$name] = '';
                $sink($body, (string)$name);
                $progress = true;
            }
            foreach ($this->dynamicMethodHelpers as $name => $body) {
                if (isset($writtenDyn[$name])) { continue; }
                $writtenDyn[$name] = true;
                $this->dynamicMethodHelpers[$name] = '';
                $sink($body, (string)$name);
                $progress = true;
            }
        }
    }
    /** Scratch: whether the last free-function call {@see EmitLlvmCalls::emitCall}
     *  emitted went through `emitBuiltin` rather than a `@manticore_*` body. */
    private bool $lastCallWasBuiltin = false;
    /** Scratch: address reg set by foreachElemAddr / foreachKeyAddr. */
    private string $feAddr = '';
    /** Scratch: result reg set by emitVirtualDispatch. */
    private string $vdResult = '';
    /** Scratch: per-arm argument list set by {@see EmitLlvmObjects::vdArmArgs}. */
    private string $vdArmList = '';

    /** How many arguments the CALL SITE actually wrote (`$this` included), before
     *  the fallback's default pad widened the shared dispatch list. Each arm cuts
     *  back to this and re-pads from its OWN declaration
     *  ({@see Passes\EmitLlvmObjects::vdArmArity}). */
    private int $vdSiteArgc = 0;
    /** Post-call release of the by-ref pad slots the latest {@see Passes\EmitLlvmObjects::vdArmArity}
     *  created for its arm ({@see omittedRefSlotDrop}); emitted right after that arm's call. */
    private string $vdArmDrops = '';
    /** Scratch: caller slot address / scratch cell slot of the by-ref argument
     *  {@see EmitLlvmCalls::emitByRefCellBox} just boxed. */
    private string $refBoxSlot = '';
    private string $refBoxTmp = '';
    /**
     * Scratch: a `...$arr` spread the current method call has NOT expanded into
     * its shared argument list, as `[arrPtrReg, firstParam, elementType]`. A
     * spread's length and the DEFAULTS behind it belong to the callee, and an
     * erased receiver's arms need not share a signature — so each arm expands
     * it against its OWN params ({@see EmitLlvmObjects::vdArmArgs}). Null when
     * the call site has no spread.
     * @var array{0:string,1:int,2:?Type}|null
     */
    private ?array $spreadTail = null;
    /**
     * @var array<string,bool> params of the function being emitted whose
     * DECLARED hint was a bare `array`. The lowered type erased to unknown
     * (LowerTypes has no branch for a bare `array`), so the hint is the only
     * remaining evidence that the i64 in the slot is an array pointer — which
     * truthiness has to know, an empty array being falsy but its pointer
     * non-null. Reset per function by emitFunction.
     */
    private array $arrayHintedParams = [];
    /** @var array<string, bool> the by-REFERENCE `array &$x` params — truthiness only: the
     *  word read through the reference may be a raw array pointer or a tagged cell */
    private array $arrayHintedRefParams = [];
    /** @var string[] module global cell names (static props/locals/global) */
    private array $globalNames = [];
    /** @var Node[] parallel default-init nodes for $globalNames */
    private array $globalDefaults = [];
    /** @var bool[] parallel prelude flags for $globalNames — linkonce_odr when
     *  true, because the prelude is compiled into every module and external
     *  linkage would make stdlib.o and user.o define the same cell twice
     *  ({@see \Compile\Mir\Module::$globalIsPrelude}). */
    private array $globalIsPrelude = [];
    /** @var bool[] parallel extern flags for $globalNames — a static property of
     *  an IMPORTED class, defined in the dependency's `.o`, so this module emits
     *  `external global` and links to it
     *  ({@see \Compile\Mir\Module::$globalIsExtern}). */
    private array $globalIsExtern = [];
    /** @var string[] names declared `global $x` — __main shares the cell */
    private array $globalVarNames = [];
    /** @var string[] the subset reached through `$GLOBALS['x']` syntax
     *  ({@see \Compile\Mir\Module::$globalsViewNames}) — those slots box. */
    private array $globalsViewNames = [];

    /** Per-module runtime-feature demand set (fresh each {@see emit}). */
    private ?RuntimeFeatures $rt = null;

    /** @var array<string, MethodMeta> free functions a ReflectionFunction reflects.
     *  Declared LAST — a new field mid-class shifts later offsets, a self-host
     *  layout hazard (the ClassDef::$isPreludeClass lesson). */
    private array $reflFnMeta = [];

    /** `#[\Deprecated]` / `#[\NoDiscard]` diagnostic bodies, from the module.
     *  Keyed by function name / "DeclaringClass::method".
     *  @var array<string, string> */
    private array $deprecatedFns = [];
    /** @var array<string, string> */
    private array $deprecatedMethods = [];
    /** @var array<string, string> */
    private array $noDiscardFns = [];
    /** @var array<string, string> */
    private array $noDiscardMethods = [];
    /** "<declClass>|<kind>|<member>|<k>" → newInstance()'s baked \Error message.
     *  @var array<string, string> */
    private array $attrSiteErrors = [];

    public function emit(Module $module): string
    {
        $this->rt = new RuntimeFeatures();
        // Arena arrays force the arena runtime on: the unified-array grow /
        // promote / index paths reference @__mir_arena_* under this flag, so
        // those symbols must be emitted even if no string took the arena path.
        if (\Compile\Debug::$arenaArrays
            || \Compile\Debug::$memoryMode === \Compile\Debug::MEM_ARENA) {
            $this->rt->needsArena = true;
        }
        // A program module (not the bundled stdlib) always links stdlib.o, which
        // CAN throw even when the user's own code never does. The exception
        // runtime — @main's depth:=1 + base landing pad and the process-global
        // jmp state — is what makes any throw land; gated on the caller's own
        // `needsExceptions` it would be absent for e.g. `<?php stat($p);`, and a
        // stdlib throw would then read an uninitialised depth 0 → slot -1 → a bogus
        // "Maximum try nesting" fatal instead of a clean uncaught error. Force it
        // on for every program (a lone base setjmp + BSS; no-op if nothing throws).
        if (!$this->emitLibrary) { $this->rt->needsExceptions = true; }
        $this->pool = new StringPool();
        $this->functionTextCounter = 0;
        $this->ssa = new SsaBuilder();
        $this->gen = new GeneratorContext();
        $this->cf = new ControlFlow();
        $this->frame = new FunctionEmitFrame();
        $this->readCellGuardFlags();
        $this->irCensus = \getenv('MANTICORE_IR_CENSUS') === '1';
        $this->censusBytes = [];
        $this->censusCount = [];
        $this->censusChild = [];
        $this->resetCellGuardFrame();
        $this->sigs = new FunctionSignatures();
        $this->arena = new ArenaContext();
        $this->locals = new LocalSlots();
        $this->lib = new RuntimeLibrary();
        $this->classes = $module->classes;
        $this->methodHoldersIdx = [];
        $this->methodHoldersClassCount = -1;
        $this->classImplementsCache = [];
        $this->classImplementsIfaceCache = [];
        $this->classIsACache = [];
        $this->selfDescendantsCache = [];
        $this->fixedPropertyHoldersCache = [];
        $this->bagClassNamesCache = [];
        $this->magicPropertyHoldersCache = [];
        $this->propertyReadHelpers = [];
        $this->dynamicMethodHelpers = [];
        $this->dynamicMethodAbiDisabled = false;
        $this->reflectNames = $module->reflectNames;
        $this->reflectAll = $module->reflectAll;
        $this->hasClassAlias = $module->hasClassAlias;
        $this->dynamicMethodMeta = $module->needsDynamicMethodMeta;
        $this->callableArrayMethods = $module->callableArrayMethods;
        $this->enums = $module->enums;
        $this->own = new \Compile\Mir\Ownership(\Compile\Mir\OwnershipContext::fromModule($module));
        $this->typeDefs = $module->typeDefs;
        $this->methodDisplay = $module->needsBacktrace ? $module->methodDisplay : [];
        $this->interfaceNames = $module->interfaceNames;
        $this->internalInterfaceNames = $module->internalInterfaceNames;
        $this->interfaceAncestors = $module->interfaceAncestors;
        $this->traitNames = $module->traitNames;
        $this->reflFnMeta = $module->reflFnMeta;
        $this->deprecatedFns = $module->deprecatedFns;
        $this->deprecatedMethods = $module->deprecatedMethods;
        $this->noDiscardFns = $module->noDiscardFns;
        $this->noDiscardMethods = $module->noDiscardMethods;
        $this->attrSiteErrors = $module->attrSiteErrors;
        $this->closureCaptures = $module->closureCaptures;
        $this->closureHasThis = $module->closureHasThis;
        $this->globalNames = $module->globalNames;
        $this->globalDefaults = $module->globalDefaults;
        $this->globalIsPrelude = $module->globalIsPrelude;
        $this->globalIsExtern = $module->globalIsExtern;
        $this->globalVarNames = $module->globalVarNames;
        $this->globalsViewNames = $module->globalsViewNames;
        $this->includeSlots = $module->includeSlots;
        $this->builtinTwinReq = $module->builtinTwinReq;
        $this->builtinTwinRet = $module->builtinTwinRet;
        // Only a twin the program NAMES in a string literal can be the target of
        // a call by name. Arming every one at every dynamic site inlined builtins
        // the program never uses — `gc_collect_cycles` pulled the cycle
        // collector into the module, and its possible-root buffering delayed
        // destructors that php runs at once.
        $this->builtinTwinTot = [];
        if ($module->builtinTwinTot !== []) {
            /** @var array<string, bool> $named */
            $named = [];
            foreach ($module->functions as $tf) {
                if ($tf->isExtern) { continue; }
                $this->collectTwinNames($tf->body, $module->builtinTwinTot, $named);
            }
            foreach ($named as $tn => $unused) {
                $this->builtinTwinTot[$tn] = $module->builtinTwinTot[$tn];
            }
        }
        $this->callableShims = $module->callableShims;
        $this->knownFnNames = $module->knownFnNames;
        if (\count($module->knownFnNames) > 0) { $this->rt->needsFnExists = true; }
        $this->rt->needsBacktrace = $module->needsBacktrace;
        // ⚠ `|| $this->emitLibrary`: a LIBRARY CANNOT KNOW ITS CALLERS. Every
        // stdlib walker — in_array, the json encoder, sort, the array_* family —
        // receives arrays built by USER modules, and one of those elements may
        // hold a reference. Asking `$module->hasRefCells` is right for a program
        // (it knows whether IT holds any) and exactly wrong for the stdlib: with
        // it off, in_array compared the BOX ADDRESS and json_encode SIGSEGVed on
        // a cell tagged REF, while the identical PHP compiled into a user module
        // was correct. See docs/design/reference-cells.md.
        $this->rt->needsRefCells = ($module->hasRefCells || $this->emitLibrary)
            && \Compile\Debug::$refCells;
        $this->needsErrorHandlers = $module->needsErrorHandlers;
        $this->needsOb = $module->needsOb;
        $this->hasObjToStr = $module->hasObjToStr;
        $this->sourceFile = $module->sourceFile;
        // A dynamic invoke has no per-callee mask; this union is the gate that
        // decides whether the run-time by-ref machinery is emitted at all.
        $this->sigs->closureRefUnion = FunctionSignatures::closureRefUnion(
            $module->functions, $module->closureCaptures);
        // Per-function by-ref + tagged(cell) param masks for call sites.
        foreach ($module->functions as $fn) {
            $mask = [];
            $tmask = [];
            $camask = [];
            $ahmask = [];
            $vmask = [];
            $ptypes = [];
            $pdefs = [];
            foreach ($fn->params as $p) {
                $mask[] = $p->byRef;
                $tmask[] = ($p->type->kind === Type::KIND_CELL);
                $camask[] = $p->cellArg;
                $ahmask[] = $p->arrayHinted;
                $vmask[] = $p->variadic;
                $ptypes[] = $p->type;
                $pdefs[] = $p->default;
            }
            $this->sigs->refParams[$fn->name] = $mask;
            $this->sigs->taggedParams[$fn->name] = $tmask;
            $this->sigs->cellArgParams[$fn->name] = $camask;
            $this->sigs->arrayHintedParams[$fn->name] = $ahmask;
            $this->sigs->variadicParams[$fn->name] = $vmask;
            $this->sigs->paramTypes[$fn->name] = $ptypes;
            $this->sigs->paramDefaults[$fn->name] = $pdefs;
            $this->sigs->returnsByRef[$fn->name] = $fn->returnsByRef;
            $this->sigs->returnType[$fn->name] = $fn->returnType;
            $this->sigs->usesFuncArgs[$fn->name] = $fn->usesFuncArgs;
            // One callee that asks arms the channel for the whole module: the
            // push is per-call-site, but the global and its take helper are
            // emitted once, here, before any body is.
            if ($fn->usesFuncArgs) { $this->rt->needsFuncArgs = true; }
            $this->definedFns[$this->mangle($fn->name)] = true;
            if ($fn->ffiSymbol !== null) { $this->ffiFnNames[$fn->name] = true; }
            if ($fn->name === '__main') { $this->moduleHasMain = true; }
            // The demand-gated fiber prelude is present iff the program uses
            // \Fiber ⇒ settle needsFibers BEFORE the preamble emits its module
            // asm + @__mir_current_fiber (mirrors the needsExceptions pre-scan).
            if ($fn->name === '__mc_fiber_run') { $this->rt->needsFibers = true; }
        }
        // Pre-scan for `$gen->throw($e)`: a yield resume point must check for
        // an injected exception. Must be known BEFORE emitting any generator
        // body (emitYield emits the check inline). Over-triggering on a user
        // `->throw()` method only adds a dead load+branch + one global.
        $this->gen->throwUsed = false;
        foreach ($module->functions as $fn) {
            if ($this->scanGenThrow($fn->body)) { $this->gen->throwUsed = true; break; }
        }
        if ($this->gen->throwUsed) { $this->rt->needsExceptions = true; }
        // Pre-scan for throw / try-catch so `needsExceptions` is settled BEFORE
        // any function body emits — @main's base landing pad (emitMain) is gated
        // on it and @main may be emitted before a throwing function is reached.
        if (!$this->rt->needsExceptions) {
            foreach ($module->functions as $fn) {
                if ($this->scanUsesExceptions($fn->body)) { $this->rt->needsExceptions = true; break; }
            }
        }
        // A readonly property write emits a synthesized `throw Error` at emit time
        // (see emitStoreProperty) that the scan above can't see, so the base
        // landing pad must be set up if any class has a readonly property.
        if (!$this->rt->needsExceptions) {
            foreach ($this->classes as $cd) {
                if ($cd->propertyReadonly !== []) { $this->rt->needsExceptions = true; break; }
            }
        }
        // Per-module property usage facts for the OWNERSHIP decisions (a cell
        // property's REPR is its declaration's, {@see cellPropBoxed}).
        $this->cellPropArrayBase = [];
        $this->refCellPropNames = [];
        $this->needsObjectVarsFn = false;
        $this->needsGetClassFn = false;
        $this->needsBagOfFn = false;
        $this->needsBoxUnknownFn = false;
        $this->eidxNeeded = [];
        $this->newDynNeeded = [];
        $this->erasedIfaceIface = [];
        $this->erasedIfaceMethod = [];
        $this->erasedIfaceArgc = [];
        $this->modToken = \dechex($this->fnvHash64($module->sourceFile
            . '|' . ($this->emitLibrary ? 'lib' : 'app')));
        $this->vdSyms = [];
        $this->vdExtraBodies = '';
        $this->cloneErasedSym = '';
        $this->dynmSyms = [];
        $this->dynmExtraBodies = '';
        $this->scmpSyms = [];
        $this->scmpExtraBodies = '';
        $this->dynfThunks = [];
        $this->dynfTables = [];
        $this->dynfExtraBodies = '';
        $this->litTableBodies = '';
        $this->litTableCount = 0;
        $this->objTemplates = [];
        $this->litTablesFlushed = false;
        $this->btBaseLine = [];
        $this->dynScopeRelTables = [];
        $this->newDynTableCache = null;
        $this->classlessCandidatesMemo = [];
        $this->dynfLookupEmitted = false;
        $this->needsInclResolveFn = false;
        $this->propOwnElem = [];
        $this->propOwnElemVeto = [];
        $this->clonedClasses = [];
        $this->cloneClassUnknown = false;
        $this->moduleIsLibrary = $module->isLibraryModule;
        $this->grantBagsForDynamicStores($module);
        $statT = \Compile\Stats::now();
        foreach ($module->functions as $fn) {
            $this->scanCellPropStores($fn->body);
            $this->collectRefCellPropNames($fn->body);
        }
        \Compile\Stats::step('  property scan', $statT, -1, -1);
        $streaming = $this->streamIrPath !== '';
        $bodyPath = $streaming ? $this->streamIrPath . '.bodies' : '';
        $bodyBytes = 0;
        $hoistedAllocas = 0;
        $fileHoistThreshold = 262144;
        $fileHoistedBodies = 0;
        $fileHoistedBytes = 0;
        if ($streaming && !\Manticore\write_file($bodyPath, '')) {
            throw new \RuntimeException('EmitLlvm: cannot create staged body file ' . $bodyPath);
        }
        $this->rootSnapshot('pre-function-emission', $module, true);
        /** @var string[] $functionBodyChunks */
        $functionBodyChunks = [];
        // MANTICORE_EMIT_TRACE=1 logs each function name to stderr right BEFORE
        // it is emitted — the last line printed before a codegen SIGSEGV names the
        // offending function. Off by default (one env read, not per-function).
        $emitTrace = \getenv('MANTICORE_EMIT_TRACE') !== false;
        $emitTraceFull = \getenv('MANTICORE_EMIT_TRACE') === 'full';
        $emitIndex = 0;
        // ⚠ The FATTEST body and the COSTLIEST one are different questions, and
        // conflating them sent this hunt down a wrong path: a batch that jumped
        // +5 GB was blamed on its fattest function, while a stand shows a fat
        // body costs only 4-6x its own bytes (and the ratio FALLS with size).
        // So track the RSS delta per function too, and report the max of each.
        $batchMaxRss = 0;
        $batchMaxRssIndex = 0;
        $batchMaxRssName = '';
        // Fattest body seen since the last batch line. The name is captured
        // through `substr`, whose result is a FRESH owned string — a plain
        // `$n = $fn->name` would be a borrowed property read held across the
        // rest of the loop, the same shape as the sink-marker landmine below,
        // and `$module->functions` is DRAINED during emission, so resolving the
        // name later from the index finds nothing (it printed `?`).
        $batchMaxBytes = 0;
        $batchMaxIndex = 0;
        $batchMaxName = '';
        // Under MANTICORE_STATS, report every function whose IR crosses
        // FAT_FN_IR bytes. A megamorphic dispatch site (one switch arm per
        // implementing class) shows up here by name — the IR-size explosion is
        // per-function, so a top-N list beats a total.
        $fatFn = 262144;
        // All module-wide pre-scans are complete above. Emit from a key snapshot
        // so each finished FunctionDef can be removed from the MIR module table;
        // keeping that table alive would retain params/defaults/types and any
        // graph reachable from them for the whole late-Emit phase. The emitter
        // already copied the signature facts into $this->sigs, and no code below
        // this loop reads $module->functions again.
        $functionKeys = \array_keys($module->functions);
        foreach ($functionKeys as $functionKey) {
            $fn = $module->functions[$functionKey];
            $emitIndex = $emitIndex + 1;
            if ($emitTrace) { \error_log('emit-trace: ' . $fn->name); }
            // Peak RSS before this body exists. Read here, OUTSIDE the sink-marker
            // window that starts below.
            $rssBefore = \Compile\Stats::reporting() ? \memory_get_usage() : 0;
            $body = $this->emitFunction($fn);
            $rawBodyPath = '';
            if ($streaming && \strlen($body) > 0 && $body[0] === "\x1f") {
                $meta = \explode("\n", $body);
                if (\count($meta) < 3 || $meta[1] === '') {
                    throw new \RuntimeException('EmitLlvm: malformed function sink marker');
                }
                $rawBodyPath = $meta[1];
                $rawBodyBytes = (int)$meta[2];
                // $meta MUST outlive $rawBodyPath: the element read hands the string
                // over BORROWED, so unsetting the array here freed the very bytes
                // $rawBodyPath points at. It then read back empty, the marker branch
                // below was skipped, and the fallback staged an already-unset $body —
                // the fat function silently lost its `define` and its .fnraw file was
                // orphaned. $meta is three short strings; it costs nothing to keep.
                unset($body);
            } else {
                $rawBodyBytes = \strlen($body);
            }
            if ($emitTraceFull) {
                // rss is the process PEAK, so the delta between two consecutive
                // lines is what emitting THIS body cost that never came back —
                // the only way to see the build multiplier of one fat function
                // without a 20-minute tier run.
                \error_log('emit-trace-body index=' . (string)$emitIndex . ' name=' . $fn->name
                    . ' raw_bytes=' . (string)$rawBodyBytes . ' cumulative_before=' . (string)$bodyBytes
                    . ' rss=' . (string)\intdiv(\memory_get_usage(), 1048576) . 'MB');
            }
            // ⚠⚠ THIS LINE IS A LANDMINE, and it is deliberately left under the
            // full `Stats::$on` rather than promoted to the phase trace.
            // `$rawBodyPath` above is a BORROWED array element whose buffer
            // `unset($meta, $body)` has already freed — the emitter takes no
            // reference for `$x = $arr[$i]` ({@see EmitLlvmLocals::emitStoreLocal}
            // retains only a LOAD_LOCAL obj/string and an array PROPERTY read).
            // This `Stats::line` then allocates into that freed buffer, and the
            // throw at the end of the streaming branch reports
            // `cannot hoist sink file stats: 17810ms fat fn: …`. Reproduces on
            // the SELF-BUILD in 18 s, identically before and after the rc work.
            // Do not add another allocation between the marker read and its use.
            // The real fix is ownership, not placement: an element read stored
            // into a local must OWN, which also needs InsertMemoryOps to plant
            // the matching release, or the retain leaks. Own epic.
            if (\Compile\Stats::$on && $rawBodyBytes >= $fatFn) {
                \Compile\Stats::line('fat fn: ' . (string)$rawBodyBytes . ' bytes  ' . $fn->name);
            }
            if ($streaming) {
                if ($rawBodyPath !== '') {
                    // FunctionTextSink already wrote this fat body directly to a
                    // private file. Hoist it without ever recreating the body as
                    // a PHP string, then append the rewritten stream and retire
                    // both temporary paths immediately.
                    $hoistedPath = $rawBodyPath . '.hoisted';
                    $h = new \Compile\Mir\HoistAllocas();
                    if (!$h->runFile($rawBodyPath, $hoistedPath)) {
                        throw new \RuntimeException('EmitLlvm: cannot hoist sink file ' . $rawBodyPath);
                    }
                    $hoistedAllocas += $h->moved;
                    $fileHoistedBodies += 1;
                    $fileHoistedBytes += $rawBodyBytes;
                    if (!\Manticore\append_file_path($hoistedPath, $bodyPath)) {
                        throw new \RuntimeException('EmitLlvm: cannot append hoisted sink file ' . $hoistedPath);
                    }
                    // The separator every OTHER body carries. emitFunction appends
                    // "\n\n" to what it returns, but for a spilled body that is the
                    // MARKER, not the text — so the file ends at `}` and the next
                    // body lands on the same line as `}define …`. LLVM does not
                    // care, but every line-oriented consumer does: HoistAllocas
                    // stops recognising the define, and SplitModule never sees the
                    // body close, so it swallows the following functions and the
                    // part that calls them gets neither a define nor a declare.
                    if (!\Manticore\append_file_bytes($bodyPath, "\n\n")) {
                        throw new \RuntimeException('EmitLlvm: cannot append sink separator');
                    }
                    \Manticore\sys_unlink($rawBodyPath);
                    \Manticore\sys_unlink($hoistedPath);
                    $bodyBytes += $rawBodyBytes;
                } elseif ($rawBodyBytes < $fileHoistThreshold) {
                    // Small functions do not justify a filesystem round-trip. Keep
                // their bounded in-memory rewrite; only fat bodies use the file
                // path, which avoids raw+hoisted duplication at the dangerous
                // Doctrine/Symfony sizes while keeping ordinary emission fast.
                $h = new \Compile\Mir\HoistAllocas();
                $body = $h->run($body);
                $hoistedAllocas += $h->moved;
                if (!\Manticore\append_file_bytes($bodyPath, $body)) {
                    throw new \RuntimeException('EmitLlvm: cannot append in-memory body');
                }
                unset($body);
                $bodyBytes += $rawBodyBytes;
                } else {
                    // This fallback covers special emitters that still return a
                    // string larger than the threshold (for example a generator).
                    // Do not keep the raw function body beside HoistAllocas' rewritten
                    // copy. The old path materialized `$body`, then `run()` exploded
                    // it into lines and joined a second full string. For a large
                    // Doctrine function that creates a needless two-body peak.
                    $fnPath = $bodyPath . '.fn.' . (string)$emitIndex;
                    $hoistedPath = $fnPath . '.hoisted';
                    if (!\Manticore\write_file($fnPath, $body)) {
                        throw new \RuntimeException('EmitLlvm: cannot stage function body ' . $fnPath);
                    }
                    $nBody = $rawBodyBytes;
                    unset($body);
                    $h = new \Compile\Mir\HoistAllocas();
                    if (!$h->runFile($fnPath, $hoistedPath)) {
                        throw new \RuntimeException('EmitLlvm: cannot hoist staged function body ' . $fnPath);
                    }
                    $hoistedAllocas += $h->moved;
                    $fileHoistedBodies += 1;
                    $fileHoistedBytes += $nBody;
                    if ($emitTraceFull) {
                        \error_log('emit-trace-hoisted index=' . (string)$emitIndex . ' name=' . $fn->name
                            . ' bytes=' . (string)$nBody . ' moved=' . (string)$h->moved
                            . ' cumulative_after=' . (string)($bodyBytes + $nBody));
                    }
                    if (!\Manticore\append_file_path($hoistedPath, $bodyPath)) {
                        throw new \RuntimeException('EmitLlvm: cannot append staged hoisted body ' . $hoistedPath);
                    }
                    \Manticore\sys_unlink($fnPath);
                    \Manticore\sys_unlink($hoistedPath);
                    $bodyBytes += $nBody;
                }
            } else {
                $functionBodyChunks[] = $body;
                $bodyBytes += $rawBodyBytes;
                if ($emitTraceFull) {
                    \error_log('emit-trace-kept index=' . (string)$emitIndex . ' name=' . $fn->name
                        . ' cumulative_after=' . (string)$bodyBytes);
                }
            }
            // This function's MIR is spent: its text is in $functionBodies and
            // nothing below reads a body again — not the preamble, not
            // HoistAllocas or PruneIr (both run on the TEXT), not the driver,
            // which only counts the functions. Dropping it here is what keeps
            // the whole MIR from standing alongside the whole IR text; the MIR
            // Periodically run the compiler-only collector while Emit itself is
            // long-lived. Pass-boundary releases cannot reclaim candidate roots
            // accumulated by tens of thousands of emitted functions; this call
            // never resets the target arena or changes ABI/COW semantics.
            // ⚠ HERE, not up beside `$rawBodyBytes`. `substr` ALLOCATES, and up
            // there it lands inside the sink-marker window and rewrites
            // `$rawBodyPath` — which is not a theory: putting it there failed
            // the T6 build at function 46088 with
            // `cannot hoist sink file …fnraw.46088.hoisted`, the path variable
            // holding the value of the `$hoistedPath` concat beside it. By this
            // point the marker is consumed and retired, so allocating is safe.
            if (\Compile\Stats::reporting()) {
                if ($rawBodyBytes > $batchMaxBytes) {
                    $batchMaxBytes = $rawBodyBytes;
                    $batchMaxIndex = $emitIndex;
                    $batchMaxName = \substr($fn->name, 0);
                }
                // Peak RSS never falls, so this delta is what emitting THIS body
                // added and never gave back.
                $grew = \memory_get_usage() - $rssBefore;
                if ($grew > $batchMaxRss) {
                    $batchMaxRss = $grew;
                    $batchMaxRssIndex = $emitIndex;
                    $batchMaxRssName = \substr($fn->name, 0);
                }
            }
            if (($emitIndex & 1023) === 0) {
                \Manticore\Allocator::release('emit-batch-' . (string)$emitIndex);
                $this->compactEmissionCaches();
                $this->rootSnapshot('emit-batch-' . (string)$emitIndex, $module, true, $bodyBytes);
                // EMISSION is ONE opaque line in the phase timeline while it is
                // the stage that owns the peak: on T6 the whole front finishes
                // at 7 970 MB and emission takes the run past 40 GB. A line per
                // batch names WHERE inside emission the memory goes, which no
                // outside sampler can do.
                //
                // ⚠ Placed at the BATCH BOUNDARY on purpose, not for tidiness.
                // Anywhere between the sink-marker read and its use (`$rawBodyPath
                // = $meta[1]` … `unset($meta, $body)` … `if ($rawBodyPath !== '')`)
                // an allocation lands in the freed buffer of that BORROWED array
                // element and rewrites the path — which is exactly how
                // `Stats::line`'s fat-fn report below turns the next throw into
                // `cannot hoist sink file stats: 17810ms fat fn: …`. Here the
                // path is already consumed and retired.
                if (\Compile\Stats::reporting()) {
                    \Compile\Stats::line('emit batch ' . (string)$emitIndex
                        . '/' . (string)\count($functionKeys)
                        . '  ir=' . (string)\intdiv($bodyBytes, 1048576) . 'MB'
                        . '  fattest=' . (string)\intdiv($batchMaxBytes, 1024) . 'KB @'
                        . (string)$batchMaxIndex . ' ' . $batchMaxName
                        . '  costliest=' . (string)\intdiv($batchMaxRss, 1048576) . 'MB @'
                        . (string)$batchMaxRssIndex . ' ' . $batchMaxRssName);
                    $batchMaxBytes = 0;
                    $batchMaxIndex = 0;
                    $batchMaxName = '';
                    $batchMaxRss = 0;
                    $batchMaxRssIndex = 0;
                    $batchMaxRssName = '';
                }
            }
            // is the retention term (a `dump-mir` of a 510 KB input peaks at
            // 193 MB, and 99.9% of the live blocks at that moment are 64-byte
            // nodes). An empty Block, not null: the field is typed.
            $fn->body = new Block([], Type::void());
            // ⚠ A LIBRARY's interface is written AFTER emission, and
            // {@see \Manticore\Sig::emitModule} builds it by walking exactly this
            // table. Draining it for a library therefore produced
            // `"functions":[],"classes":[],"constants":[]` — a stdlib .o full of
            // symbols behind an interface that declares none of them, so every
            // program that called one failed clang with `use of undefined value`.
            // 531 of 1028 AOT cases were red on that alone, and it was invisible
            // because `bin/build` had been refusing its own preflight since the
            // day the drain landed. The body above is already emptied, which is
            // the retention term; the FunctionDef shell that Sig reads is small.
            //
            // $this->emitLibrary, NOT $module->isLibraryModule: the latter is
            // `emitLibrary && exportTypes` (Main.php:3852) and is false for the
            // stdlib, so guarding on it kept the sig empty. This is the same flag
            // the driver tests before writing the .sig (Main.php:2185, :2270).
            if (!$this->emitLibrary) {
                unset($module->functions[$functionKey], $fn);
            }
        }
        unset($functionKeys);
        $this->rootSnapshot('post-function-emission', $module, true, $bodyBytes);
        // One `__mc_drop` per capturing closure literal seen above — it releases
        // the captures its env co-owns, and its address is already stamped into
        // every env {@see EmitLlvmCalls::emitClosure}.
        $extraBodies = $this->emitClosureDropFns();
        $this->rootSnapshot('post-closure-drop-build', $module, true, $bodyBytes);
        // AFTER the bodies: the flag is set while they emit, and the body it adds
        // sets runtime flags of its own that the preamble below still reads.
        $extraBodies .= $this->emitNewDynFns();
        $extraBodies .= $this->emitErasedIndexFns();
        if ($this->needsObjectVarsFn) { $extraBodies .= $this->emitObjectVarsFn(); }
        if ($this->needsGetClassFn) { $extraBodies .= $this->emitGetClassFn(); }
        if ($this->needsBagOfFn) { $extraBodies .= $this->emitBagOfFn(); }
        if ($this->needsBoxUnknownFn) { $extraBodies .= $this->emitBoxUnknownFn(); }
        $extraBodies .= $this->emitErasedIfaceFns();
        $extraBodies .= $this->vdExtraBodies;
        $extraBodies .= $this->dynmExtraBodies;
        $extraBodies .= $this->scmpExtraBodies;
        $extraBodies .= $this->dynfExtraBodies;
        $extraBodies .= $this->litTableBodies;
        $this->litTablesFlushed = true;
        if ($this->needsInclResolveFn) { $extraBodies .= $this->emitInclResolveFn(); }
        // Erased fixed-property readers are generated lazily while ordinary
        // functions emit. Append each helper exactly once after the function
        // loop; callers then contain only a small call instead of a repeated
        // class-id switch. The preamble is emitted afterwards, so any runtime
        // demand raised by the helper is still visible to emitPreamble().
        $this->rootSnapshot('post-lazy-helper-build', $module, true, $bodyBytes);
        if ($streaming) {
            // Helper bodies are compiler-owned text and can be numerous on Doctrine.
            // Do not join all of them into one temporary PHP string: stage, hoist,
            // append, and release each body independently. This changes only the
            // compiler lifetime; the emitted LLVM order and target ABI are stable.
            $appendStreamedBody = function (string $rawBody, string $label) use (&$bodyBytes, &$hoistedAllocas, &$fileHoistedBodies, &$fileHoistedBytes, $bodyPath): void {
                if ($rawBody === '') { return; }
                $id = (string)(++$this->functionTextCounter);
                $rawPath = $bodyPath . '.helper.' . $id;
                $hoistedPath = $rawPath . '.hoisted';
                if (!\Manticore\write_file($rawPath, $rawBody)) {
                    throw new \RuntimeException('EmitLlvm: cannot stage helper body ' . $label);
                }
                $nBody = \strlen($rawBody);
                unset($rawBody);
                $h = new \Compile\Mir\HoistAllocas();
                if (!$h->runFile($rawPath, $hoistedPath)) {
                    throw new \RuntimeException('EmitLlvm: cannot hoist helper body ' . $label);
                }
                $hoistedAllocas += $h->moved;
                $fileHoistedBodies += 1;
                $fileHoistedBytes += $nBody;
                if (!\Manticore\append_file_path($hoistedPath, $bodyPath)) {
                    throw new \RuntimeException('EmitLlvm: cannot append helper body ' . $label);
                }
                \Manticore\sys_unlink($rawPath);
                \Manticore\sys_unlink($hoistedPath);
                $bodyBytes += $nBody;
            };
            $h = new \Compile\Mir\HoistAllocas();
            $extraBodies = $h->run($extraBodies);
            $hoistedAllocas += $h->moved;
            if (!\Manticore\append_file_bytes($bodyPath, $extraBodies)) {
                throw new \RuntimeException('EmitLlvm: cannot append staged body file ' . $bodyPath);
            }
            $bodyBytes += \strlen($extraBodies);
            unset($extraBodies);
            $this->drainLazyHelpers($appendStreamedBody);
            unset($appendStreamedBody);
            \Compile\Stats::line('IR: streamed bodies ' . (string)$bodyBytes . ' bytes');
            $this->reportIrCensus($bodyBytes);
            \Compile\Stats::line('IR: file-hoisted bodies ' . (string)$fileHoistedBodies
                . ' (' . (string)$fileHoistedBytes . ' bytes; threshold '
                . (string)$fileHoistThreshold . ')');
            $this->rootSnapshot('post-extra-body-merge', $module, true, $bodyBytes);
        } else {
            $functionBodyChunks[] = $extraBodies;
            $bodyBytes += \strlen($extraBodies);
            // The buffered path needs the same drain. Streaming grew one and the
            // in-memory branch was left with none, so with MANTICORE_STREAM_IR=0
            // every lazy helper was generated, registered, and then dropped.
            $this->drainLazyHelpers(function (string $body, string $label) use (&$functionBodyChunks, &$bodyBytes): void {
                if ($body === '') { return; }
                $functionBodyChunks[] = $body;
                $bodyBytes += \strlen($body);
            });
            \Compile\Stats::line('IR: bodies ' . (string)$bodyBytes . ' bytes');
        }
        // Mark every RUNTIME helper (`@__mir_*`, `@__manticore_*`, cc/box
        // helpers) `linkonce_odr` so the linker dedups them when a user `.o`
        // is linked against the prebuilt `stdlib.o` — both objects carry the
        // same preamble. Only the preamble block is rewritten; user / stdlib
        // PHP functions stay external (unique) and `@main` lives in the
        // bodies, never the preamble. linkonce_odr is a no-op for a lone `.o`.
        $statT = \Compile\Stats::now();
        $preamble = $this->linkonceRuntime($this->emitPreamble());
        // The preamble mints lazy helpers too (a per-class `__mir_props_*` body
        // cellifying an array property), after both drains above ran. Drained
        // entries are blanked, so this only picks up the late ones.
        $late = '';
        $this->drainLazyHelpers(function (string $body, string $label) use (&$late): void { $late .= $body; });
        $preamble .= $late;
        unset($late);
        \Compile\Stats::step('  emit preamble', $statT, -1, -1);
        \Compile\Stats::line('IR: preamble ' . (string)\strlen($preamble) . ' bytes');
        if ($streaming) {
            if (!\Manticore\write_file($this->streamIrPath, $this->framePointerChunk($preamble))
                || !\Manticore\append_file_path($bodyPath, $this->streamIrPath)) {
                throw new \RuntimeException('EmitLlvm: cannot finalize staged IR ' . $this->streamIrPath);
            }
            if (\Compile\Debug::$framePointers
                && !\Manticore\append_file_bytes($this->streamIrPath,
                    "\nattributes #0 = { \"frame-pointer\"=\"all\" }\n")) {
                throw new \RuntimeException('EmitLlvm: cannot append staged IR attributes');
            }
            \Manticore\sys_unlink($bodyPath);
            \Compile\Stats::step('  hoist allocas (streamed)', $statT, $hoistedAllocas, -1);
            $stagedBytes = \strlen($preamble) + $bodyBytes;
            // PruneIr used to be unreachable from here: this branch returns the
            // staged marker before the in-memory pruner below ever runs, so every
            // manifest build has been handing clang the discardable helpers it
            // could have dropped. Same gate as that pruner — never for a library,
            // whose `.sig` makes "unreferenced here" say nothing about need.
            $pruneMode = \getenv('MANTICORE_PRUNE_IR');
            if (!$this->emitLibrary && $pruneMode !== 'off'
                && \Compile\Mir\PruneIr::shouldRunFile($stagedBytes)) {
                $statT = \Compile\Stats::now();
                $prune = new \Compile\Mir\PruneIr();
                $prunedPath = $this->streamIrPath . '.pruned';
                if (!$prune->runFile($this->streamIrPath, $prunedPath)) {
                    throw new \RuntimeException('EmitLlvm: cannot prune staged IR ' . $this->streamIrPath);
                }
                if ($prune->dropped > 0) {
                    \Manticore\sys_rename($prunedPath, $this->streamIrPath);
                    $stagedBytes = $stagedBytes - $prune->droppedBytes;
                }
                \Compile\Stats::step('  prune staged IR', $statT, $prune->kept, $prune->dropped);
                \Compile\Stats::line('IR: pruned ' . (string)$prune->dropped . ' of '
                    . (string)($prune->dropped + $prune->kept) . ' defs, '
                    . (string)$stagedBytes . ' bytes');
            }
            \Compile\Stats::line('IR: staged at ' . $this->streamIrPath . ' ('
                . (string)$stagedBytes . ' bytes)');
            // This streaming path is the DEFAULT (Main.php sets streamIrPath
            // unless MANTICORE_STREAM_IR is set) and returns here, before the
            // in-memory branch below ever runs — the summary must fire on
            // THIS exit too, or it silently never prints on an ordinary
            // `bin/manticore compile` / `bin/build`. Side channel only
            // (`\error_log`), never appended to the staged-IR marker string.
            if ($this->cellGuard) { \error_log($this->cellGuardSummary()); }
            if ($this->cellAssert) { $this->logCellAssertSites(); }
            return "\x1eMANTICORE_STAGED_IR\n" . $this->streamIrPath . "\n"
                . (string)$stagedBytes;
        }
        $ir = $preamble . \implode('', $functionBodyChunks);
        // The one final IR string is now the sole owner of emitted body text;
        // release the chunk array before hoist/prune to avoid retaining every
        // per-function allocation alongside the complete module.
        unset($functionBodyChunks);
        // Expression temporaries are emitted where the expression sits, so a
        // loop body's `alloca` runs once per iteration and the stack it takes is
        // not released until the function returns. -O2 hides this (mem2reg
        // promotes the slots); -O0 — which tools/selfhost.sh uses — does not,
        // and a hot loop dies on the guard page. {@see \Compile\Mir\HoistAllocas}
        $statT = \Compile\Stats::now();
        $hoist = new \Compile\Mir\HoistAllocas();
        $ir = $hoist->run($ir);
        \Compile\Stats::step('  hoist allocas', $statT, $hoist->moved, -1);
        // Everything above is emitted on DEMAND FLAGS, not on reachability: the
        // whole unified array runtime, the rc/arena/pool helpers and the
        // unconditional prelude land in every module whether or not the program
        // can reach them (`echo "hi";` emitted 203 bodies for 1 user function).
        // Delete the discardable ones now instead of paying clang to parse,
        // verify and GlobalDCE them. See {@see \Compile\Mir\PruneIr} for why
        // only `linkonce_odr` is ever touched.
        //
        // Skipped for --emit-library: `stdlib.o` is consumed through its `.sig`
        // by OTHER modules, so "unreferenced here" says nothing about whether a
        // helper is needed — and keeping the library's preamble intact is what
        // the linkonce_odr coalescing contract is written against.
        $pruneMode = \getenv('MANTICORE_PRUNE_IR');
        if (!$this->emitLibrary && $pruneMode !== 'off') {
            $statT = \Compile\Stats::now();
            $prune = new \Compile\Mir\PruneIr();
            $ir = $prune->run($ir);
            \Compile\Stats::step('  prune IR', $statT, $prune->kept, $prune->dropped);
            \Compile\Stats::line('IR: pruned ' . (string)$prune->dropped . ' of '
                . (string)($prune->dropped + $prune->kept) . ' defs, '
                . (string)\strlen($ir) . ' bytes');
        } elseif (!$this->emitLibrary && $pruneMode === 'off') {
            \Compile\Stats::line('  prune IR skipped (MANTICORE_PRUNE_IR=off)');
        }
        // One coverage line per module emission, on a side channel
        // (`\error_log`) — never appended to `$ir`, so this changes nothing a
        // build compares against.
        if ($this->cellGuard) { \error_log($this->cellGuardSummary()); }
        if ($this->cellAssert) { $this->logCellAssertSites(); }
        return $ir;
    }

    /** Apply frame-pointer attributes to a streamed chunk without emitting the
     * module-level attribute group; the group is appended once after all chunks. */
    private function framePointerChunk(string $ir): string
    {
        if (!\Compile\Debug::$framePointers) { return $ir; }
        $lines = \explode("\n", $ir);
        foreach ($lines as $i => $l) {
            if (\substr($l, 0, 7) !== 'define ') { continue; }
            if (\substr($l, -3) !== ') {') { continue; }
            $lines[$i] = \substr($l, 0, -2) . '#0 {';
        }
        return \implode("\n", $lines);
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

    /** Backing kind via a typed param (self-host slot offset). */
    /**
     * `@__mir_props_<id>(ptr %o) -> i64` for an ENUM — what php's
     * `get_object_vars()` / `(array)` answer for a case: `name`, plus `value`
     * when the enum is backed. `linkonce_odr` and derived from the EnumDef
     * alone, so every module emitting this enum emits identical bytes.
     */
    private function emitEnumPropsFn(string $name, \Compile\Mir\EnumDef $ed): string
    {
        $en = $this->mangle($name);
        $out = $this->strGlobalDef('@' . $en . '__pk_name', 'name');
        $nameKey = $this->strSymBytes('@' . $en . '__pk_name');
        $valueKey = '';
        if ($this->edBacking($ed) === 'int' || $this->edBacking($ed) === 'string') {
            $out .= $this->strGlobalDef('@' . $en . '__pk_value', 'value');
            $valueKey = $this->strSymBytes('@' . $en . '__pk_value');
        }
        $ir = $this->emitEnumVarsArray('%o', $name, $ed, $nameKey, $valueKey);
        $sym = \Compile\Mir\RuntimeLibrary::propsFnSymbol($ed->classId);
        return $out . 'define i64 ' . $sym . "(ptr %o) {\nentry:\n" . $ir
            . '  %epri = ptrtoint ptr ' . $this->lastValue . " to i64\n"
            . "  ret i64 %epri\n}\n";
    }

    private function edBacking(\Compile\Mir\EnumDef $ed): string
    {
        return $ed->backing;
    }

    /**
     * Per-enum-case SINGLETON objects, so an enum case boxed into a `mixed`/cell
     * (a heterogeneous array, a `mixed` var_dump arg) round-trips with its class
     * identity intact — box_object of the raw ORDINAL would tag a tiny int as a
     * pointer, and every generic object consumer (var_dump / ===) then derefs it
     * → SIGSEGV / wrong compare. Each singleton mimics the object layout so the
     * normal object machinery works uniformly:
     *   data-8 : ENUM_TAG_MAGIC (NOT RC_TAG_MAGIC → cell_drop / rc ops SKIP it;
     *            the case is a `constant`, immortal, never rc-touched). It used
     *            to be a plain 0, which the rc helpers skipped just as well but
     *            which a RAW erased carrier could not be told apart from junk —
     *            so `instanceof` over an erased enum case answered false.
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
        // `$name` is the raw FQN — used for the class-descriptor DEDUP (classes are
        // keyed by it) and the var_dump display string. `$en` mangles backslashes
        // for the LLVM symbol spellings (must match EmitLlvmModule / EmitLlvmObjects).
        $en = $this->mangle($name);
        $out = '';
        // Descriptor — reuse the class descriptor if a method-enum already
        // registered one (dropRuntime emits `@__mir_cd_<id>` for it); else emit.
        // The case's DECLARED "properties" — `name`, and `value` when backed —
        // reachable from a GENERIC runtime helper. `__mir_object_vars` is
        // `internal` and specialized from the EMITTING module's table, so
        // `manticore_stdlib.o` cannot see a user enum at all: `array_column(
        // Enum::cases(), 'value')` inside the stdlib answered []. The descriptor
        // is the channel the class path already uses for exactly this. Derived
        // from the EnumDef alone — identical bytes in every module that emits
        // it, which is what the linkonce_odr coalescing requires.
        $out .= $this->emitEnumPropsFn($name, $ed);
        $propsFld = 'ptr ' . \Compile\Mir\RuntimeLibrary::propsFnSymbol($ed->classId);
        if (!isset($this->classes[$name])) {
            // Same spelling as the ordinary path — the symbol coalesces by name,
            // so a type that disagreed would be one symbol defined two ways.
            $out .= \Compile\Mir\RuntimeLibrary::descriptorGlobal(
                $ed->classId, 'ptr null', 'ptr null', 'ptr null', $propsFld, 'ptr null', 0);
        }
        $descI = 'ptrtoint (ptr @__mir_cd_' . $cid . ' to i64)';
        // LLVM symbol infix must fold `\` (namespaced enums like Io\Poll\Backend
        // emit invalid `@Io\Poll\...` otherwise); the DISPLAY string keeps the
        // real FQN so get_class / enum(...) render right.
        $mn = $this->mangle($name);
        $n = \count($ed->caseNames);
        $dataPtrs = [];
        $fqnPtrs = [];
        $i = 0;
        foreach ($ed->caseNames as $cn) {
            $sym = '@' . $mn . '__case_' . (string)$i;
            $out .= $sym . ' = linkonce_odr constant { i64, i64, i64, i64 } { i64 '
                  . (string)\Compile\MemoryAbi::ENUM_TAG_MAGIC . ', i64 '
                  . $descI . ', i64 0, i64 ' . (string)$i . " }\n";
            $dataPtrs[] = 'i64 ptrtoint (ptr getelementptr (i8, ptr ' . $sym . ', i64 8) to i64)';
            $fq = '@' . $mn . '__fqn_' . (string)$i;
            $out .= $this->strGlobalDef($fq, $name . '::' . $cn);
            $fqnPtrs[] = 'ptr ' . $this->strSymBytes($fq);
            $i = $i + 1;
        }
        $out .= '@' . $mn . '__cases = linkonce_odr constant [' . (string)$n
              . ' x i64] [' . \implode(', ', $dataPtrs) . "]\n";
        $out .= '@' . $mn . '__fqns = linkonce_odr constant [' . (string)$n
              . ' x ptr] [' . \implode(', ', $fqnPtrs) . "]\n";
        return $out;
    }

    /**
     * `__mir_array_implode_cell(sep, arr) -> ptr` — join a cell-array (every
     * element NaN-boxed) by converting each element to a string via
     * `__manticore_tagged_to_str`. Mirrors __mir_array_implode but for a
     * non-string element vec (int/float/mixed): biImplode boxes the vec into a
     * cell-array first. Two passes (sum lengths, then copy with separators).
     */
    private function implodeCellRuntime(bool $withObj = false): string
    {
        // Both variants from one body: `_obj` takes the module's own
        // `__mir_obj_to_str` as a POINTER — the central core cannot name it, and
        // a Stringable element joined as its address (php-cs-fixer's
        // DocBlock::getContent). Null = no __toString class anywhere.
        if (!$withObj) { $plain = $this->implodeCellRuntime(true); } else { $plain = ''; }
        $this->libcExtra['memcpy'] = 'declare ptr @memcpy(ptr, ptr, i64)';
        $this->libcExtra['strlen'] = 'declare i64 @strlen(ptr)';
        $out  = $withObj
            ? "\ndefine ptr @__mir_array_implode_cell_obj(ptr %sep, ptr %arr, ptr %objfn) {\n"
            : $plain . "\ndefine ptr @__mir_array_implode_cell(ptr %sep, ptr %arr) {\n";
        $out .= "entry:\n";
        $out .= "  %len = call i64 @__mir_array_live_len(ptr %arr)\n";
        $out .= "  %ez = icmp sle i64 %len, 0\n";
        $out .= "  br i1 %ez, label %empty, label %init\n";
        $out .= "empty:\n  ret ptr " . $this->strSymBytes('@.ts.empty') . "\n";
        // SINGLE pass into a growing buffer: each element is formatted by
        // `__manticore_tagged_to_str` EXACTLY ONCE. The old two-pass (size, then
        // copy) called tagged_to_str per element PER PASS — a vec[float] implode
        // ran the snprintf float formatter twice per element (~2× the wall), and
        // the string-key/value strlen had to stay header-based to avoid a torn
        // read between the passes. A grow (str_alloc + memcpy + release old) is
        // amortized O(1) — the initial `8*len+16` estimate rarely regrows.
        $out .= "init:\n";
        // The elements of an ERASED carrier are not self-describing: a concrete
        // `vec[string]` literal reaching here through an erased alias stores raw
        // pointers, which tagged_to_str reads as a denormal double and joins as
        // "". Decode each element by the array's own element-kind hint first
        // (the identity at hint 0 and on an already-boxed CELL).
        $out .= "  %flagsp = getelementptr inbounds i8, ptr %arr, i64 "
              . (string)\Compile\MemoryAbi::ARRAY_FLAGS_OFFSET . "\n";
        $out .= "  %flagsw = load i64, ptr %flagsp\n";
        $out .= "  %repr = and i64 %flagsw, " . (string)\Compile\MemoryAbi::ARRAY_ELEM_HINT_MASK . "\n";
        $out .= "  %seplen = call i64 @__mir_strlen(ptr %sep)\n";
        $out .= "  %c0 = shl i64 %len, 3\n";
        $out .= "  %cap0 = add i64 %c0, 16\n";
        $out .= "  %buf0 = call ptr @__mir_str_alloc(i64 %cap0)\n";
        $out .= "  %bufp = alloca ptr\n  store ptr %buf0, ptr %bufp\n";
        $out .= "  %capp = alloca i64\n  store i64 %cap0, ptr %capp\n";
        $out .= "  %wp = alloca i64\n  store i64 0, ptr %wp\n";
        $out .= "  %ip = alloca i64\n  store i64 0, ptr %ip\n";
        $out .= "  br label %loop\n";
        $out .= "loop:\n  %i = load i64, ptr %ip\n  %ld = icmp sge i64 %i, %len\n";
        $out .= "  br i1 %ld, label %fin, label %body\n";
        $out .= "body:\n";
        $out .= "  %ev0 = call i64 @__mir_array_value_at(ptr %arr, i64 %i)\n";
        $out .= "  %ev = call i64 @__mir_box_by_repr(i64 %ev0, i64 %repr)\n";
        if ($withObj) {
            $out .= "  %eistag = icmp ugt i64 %ev, -4503599627370496\n";
            $out .= "  %eh = lshr i64 %ev, 48\n  %enib = and i64 %eh, 15\n";
            $out .= "  %eisobjn = icmp eq i64 %enib, 8\n  %eisobj = and i1 %eistag, %eisobjn\n";
            $out .= "  %ehasfn = icmp ne ptr %objfn, null\n  %euse = and i1 %eisobj, %ehasfn\n";
            $out .= "  br i1 %euse, label %eobj, label %escal\n";
            $out .= "eobj:\n  %eo = call ptr %objfn(i64 %ev)\n  br label %eend\n";
            $out .= "escal:\n  %es0 = call ptr @__manticore_tagged_to_str(i64 %ev)\n  br label %eend\n";
            $out .= "eend:\n  %es = phi ptr [ %eo, %eobj ], [ %es0, %escal ]\n";
        } else {
            $out .= "  %es = call ptr @__manticore_tagged_to_str(i64 %ev)\n";
        }
        $out .= "  %el = call i64 @__mir_strlen(ptr %es)\n";
        $out .= "  %isfirst = icmp eq i64 %i, 0\n";
        $out .= "  %sepn = select i1 %isfirst, i64 0, i64 %seplen\n";
        $out .= "  %need = add i64 %el, %sepn\n";
        $out .= "  %w = load i64, ptr %wp\n";
        $out .= "  %cap = load i64, ptr %capp\n";
        $out .= "  %after = add i64 %w, %need\n";
        $out .= "  %after1 = add i64 %after, 1\n";
        $out .= "  %fits = icmp ule i64 %after1, %cap\n";
        $out .= "  br i1 %fits, label %write, label %grow\n";
        $out .= "grow:\n";
        $out .= "  %g2 = shl i64 %cap, 1\n";
        $out .= "  %gmax = icmp ugt i64 %after1, %g2\n";
        $out .= "  %ncap = select i1 %gmax, i64 %after1, i64 %g2\n";
        $out .= "  %nbuf = call ptr @__mir_str_alloc(i64 %ncap)\n";
        $out .= "  %oldbuf = load ptr, ptr %bufp\n";
        $out .= "  call ptr @memcpy(ptr %nbuf, ptr %oldbuf, i64 %w)\n";
        $out .= "  call void @__mir_rc_release_str(ptr %oldbuf)\n";
        $out .= "  store ptr %nbuf, ptr %bufp\n";
        $out .= "  store i64 %ncap, ptr %capp\n";
        $out .= "  br label %write\n";
        $out .= "write:\n";
        $out .= "  %b = load ptr, ptr %bufp\n";
        $out .= "  br i1 %isfirst, label %wval, label %wsep\n";
        $out .= "wsep:\n";
        $out .= "  %ws = load i64, ptr %wp\n";
        $out .= "  %sd = getelementptr inbounds i8, ptr %b, i64 %ws\n";
        $out .= "  call ptr @memcpy(ptr %sd, ptr %sep, i64 %seplen)\n";
        $out .= "  %ws2 = add i64 %ws, %seplen\n  store i64 %ws2, ptr %wp\n";
        $out .= "  br label %wval\n";
        $out .= "wval:\n";
        $out .= "  %wv = load i64, ptr %wp\n";
        $out .= "  %vd = getelementptr inbounds i8, ptr %b, i64 %wv\n";
        $out .= "  call ptr @memcpy(ptr %vd, ptr %es, i64 %el)\n";
        $out .= "  %wv2 = add i64 %wv, %el\n  store i64 %wv2, ptr %wp\n";
        // Free the FRESH temp (int/float/bool → a +1 string); a STRING cell's
        // tagged_to_str hands back the RAW payload ptr (a borrow — never free).
        $out .= "  %pay = and i64 %ev, 281474976710655\n";
        $out .= "  %payp = inttoptr i64 %pay to ptr\n";
        $out .= "  %braw = icmp eq ptr %es, %payp\n";
        $out .= "  br i1 %braw, label %nextk, label %rel\n";
        $out .= "rel:\n  call void @__mir_rc_release_str(ptr %es)\n  br label %nextk\n";
        $out .= "nextk:\n";
        $out .= "  %i2 = add i64 %i, 1\n  store i64 %i2, ptr %ip\n  br label %loop\n";
        $out .= "fin:\n";
        $out .= "  %wf = load i64, ptr %wp\n";
        $out .= "  %bf = load ptr, ptr %bufp\n";
        $out .= "  %nulp = getelementptr inbounds i8, ptr %bf, i64 %wf\n";
        $out .= "  store i8 0, ptr %nulp\n";
        $out .= "  call void @__mir_str_set_len(ptr %bf, i64 %wf)\n";
        $out .= "  ret ptr %bf\n}\n";
        return $out;
    }

    private function intToStrRuntime(): string
    {
        // NOT intFmtRuntime() — the int_len/int_fmt pair has a second consumer
        // (@__mir_out_int) that does not want the whole int_to_str machinery, so
        // the caller emits it, once, for either demand.
        $out = $this->intToStrImpl('@__mir_int_to_str', '@__mir_str_alloc');
        if ($this->rt->needsArena) {
            $out .= $this->intToStrImpl('@__mir_int_to_str_arena', '@__mir_str_alloc_arena');
        }
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

    /**
     * `(string)$float` / echo / concat coercion (PHP `precision=14`). snprintf's
     * `%.14g` gives the right DIGITS but C's own scientific format ("1e+20",
     * "1e-05") differs from PHP's ("1.0E+20", "1.0E-5"): PHP forces a `.0`
     * mantissa, an uppercase `E`, and strips the exponent's leading zeros. The
     * decimal/scientific THRESHOLD is identical (verified across the boundary),
     * so only a scientific result is rewritten — a decimal one is copied out
     * unchanged, no overhead. `var_dump` / json do NOT use this (they are
     * shortest-round-trip, {@see floatShortestImpl} / the Ryu encoder).
     */
    private function floatToStrImpl(string $name, string $alloc): string
    {
        $out  = "\ndefine ptr " . $name . "(double %v) {\n";
        $out .= "entry:\n";
        // snprintf into a stack scratch, then size the heap result exactly.
        $out .= "  %tmp = alloca [40 x i8]\n";
        $out .= "  %n32 = call i32 (ptr, i64, ptr, ...) @snprintf(ptr %tmp, i64 40, ptr @.fmt.pg, double %v)\n";
        $out .= "  %n = sext i32 %n32 to i64\n";
        $out .= "  %ep = call ptr @memchr(ptr %tmp, i32 101, i64 %n)\n";   // 'e'
        $out .= "  %hase = icmp ne ptr %ep, null\n";
        $out .= "  br i1 %hase, label %sci, label %dec\n";
        // Decimal (the common case): copy the scratch out verbatim, including
        // the snprintf NUL at tmp[n] (str_alloc(k) gives exactly k content
        // bytes, so the terminator needs its own byte).
        $out .= "dec:\n";
        $out .= "  %np1 = add i64 %n, 1\n";
        $out .= "  %dbuf = call ptr " . $alloc . "(i64 %np1)\n";
        $out .= "  call ptr @memcpy(ptr %dbuf, ptr %tmp, i64 %np1)\n";
        $out .= "  call void @__mir_str_set_len(ptr %dbuf, i64 %n)\n";
        $out .= "  ret ptr %dbuf\n";
        // Scientific: rebuild `<mant>[.0]E<sign><stripped-exp>`.
        $out .= "sci:\n";
        $out .= "  %tmpi = ptrtoint ptr %tmp to i64\n";
        $out .= "  %epi = ptrtoint ptr %ep to i64\n";
        $out .= "  %p = sub i64 %epi, %tmpi\n";                            // index of 'e'
        $out .= "  %dotp = call ptr @memchr(ptr %tmp, i32 46, i64 %p)\n";  // '.' in mantissa?
        $out .= "  %hasdot = icmp ne ptr %dotp, null\n";
        // strip leading zeros of the exponent digits (keep the last digit).
        $out .= "  %estart = add i64 %p, 2\n";                             // after 'e' and sign
        $out .= "  %nm1 = sub i64 %n, 1\n";
        $out .= "  br label %zloop\n";
        $out .= "zloop:\n";
        $out .= "  %k = phi i64 [%estart, %sci], [%k1, %zadv]\n";
        $out .= "  %klt = icmp slt i64 %k, %nm1\n";
        $out .= "  br i1 %klt, label %zchk, label %zdone\n";
        $out .= "zchk:\n";
        $out .= "  %kp = getelementptr inbounds i8, ptr %tmp, i64 %k\n";
        $out .= "  %kc = load i8, ptr %kp\n";
        $out .= "  %kz = icmp eq i8 %kc, 48\n";                            // '0'
        $out .= "  br i1 %kz, label %zadv, label %zdone\n";
        $out .= "zadv:\n";
        $out .= "  %k1 = add i64 %k, 1\n";
        $out .= "  br label %zloop\n";
        $out .= "zdone:\n";
        $out .= "  %kf = phi i64 [%k, %zloop], [%k, %zchk]\n";
        $out .= "  %explen = sub i64 %n, %kf\n";
        $out .= "  %mantextra = select i1 %hasdot, i64 0, i64 2\n";        // ".0" if no dot
        $out .= "  %mantlen = add i64 %p, %mantextra\n";
        // total = mantlen + 'E' + sign + explen
        $out .= "  %t1 = add i64 %mantlen, 2\n";
        $out .= "  %total = add i64 %t1, %explen\n";
        $out .= "  %totp1 = add i64 %total, 1\n";                          // + NUL byte
        $out .= "  %buf = call ptr " . $alloc . "(i64 %totp1)\n";
        $out .= "  call ptr @memcpy(ptr %buf, ptr %tmp, i64 %p)\n";         // mantissa digits
        $out .= "  br i1 %hasdot, label %afterdot, label %adddot\n";
        $out .= "adddot:\n";
        $out .= "  %dpos = getelementptr inbounds i8, ptr %buf, i64 %p\n";
        $out .= "  store i8 46, ptr %dpos\n";                              // '.'
        $out .= "  %p1 = add i64 %p, 1\n";
        $out .= "  %zpos = getelementptr inbounds i8, ptr %buf, i64 %p1\n";
        $out .= "  store i8 48, ptr %zpos\n";                              // '0'
        $out .= "  br label %afterdot\n";
        $out .= "afterdot:\n";
        $out .= "  %epos = getelementptr inbounds i8, ptr %buf, i64 %mantlen\n";
        $out .= "  store i8 69, ptr %epos\n";                              // 'E'
        $out .= "  %sp = add i64 %p, 1\n";
        $out .= "  %signsrc = getelementptr inbounds i8, ptr %tmp, i64 %sp\n";
        $out .= "  %signc = load i8, ptr %signsrc\n";
        $out .= "  %spos = add i64 %mantlen, 1\n";
        $out .= "  %signdst = getelementptr inbounds i8, ptr %buf, i64 %spos\n";
        $out .= "  store i8 %signc, ptr %signdst\n";
        $out .= "  %dpos2 = add i64 %mantlen, 2\n";
        $out .= "  %ddst = getelementptr inbounds i8, ptr %buf, i64 %dpos2\n";
        $out .= "  %esrc = getelementptr inbounds i8, ptr %tmp, i64 %kf\n";
        $out .= "  call ptr @memcpy(ptr %ddst, ptr %esrc, i64 %explen)\n";
        $out .= "  %nulp = getelementptr inbounds i8, ptr %buf, i64 %total\n";
        $out .= "  store i8 0, ptr %nulp\n";                               // NUL-terminate
        $out .= "  call void @__mir_str_set_len(ptr %buf, i64 %total)\n";
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
        if (isset($this->mangleCache[$name])) { return $this->mangleCache[$name]; }
        // Keep fragments in an array: `.=` per byte has quadratic copy cost when
        // a generated symbol is long, and mangle is called from nearly every
        // emitter site. The resulting spelling is byte-for-byte unchanged.
        /** @var string[] $parts */
        $parts = [];
        $n = \strlen($name);
        for ($i = 0; $i < $n; $i = $i + 1) {
            $c = \substr($name, $i, 1);
            if ($c === '\\') { $parts[] = '_'; continue; }
            // php identifiers admit every byte >= 0x80, so a class can be named
            // in UTF-8 — symfony/cache declares `class \\xa9`. An LLVM identifier
            // cannot carry those bytes raw, so they are hex-escaped into a
            // still-unique ASCII name. `$u` keeps the escape from colliding with
            // an ordinary `_XX` in a source name.
            $b = \ord($c);
            $parts[] = $b >= 0x80 ? '_u' . \strtoupper(\dechex($b)) : $c;
        }
        $out = \implode('', $parts);
        $this->mangleCache[$name] = $out;
        return $out;
    }

    /** Overwrite the top backtrace frame's name (index depth-1) with `$disp`,
     *  guarded on depth>0. Emitted at a method's entry so the frame carries
     *  the exact "Class->method" / "Class::method" the callee knows. */
    private function btNameFix(string $disp): string
    {
        $d = $this->ssa->allocReg();
        $out = '  ' . $d . " = load i64, ptr @__mir_bt_depth\n";
        $c = $this->ssa->allocReg();
        $out .= '  ' . $c . ' = icmp sgt i64 ' . $d . ", 0\n";
        $set = $this->ssa->allocLabel('btfix.set');
        $end = $this->ssa->allocLabel('btfix.end');
        $out .= '  br i1 ' . $c . ', label %' . $set . ', label %' . $end . "\n" . $set . ":\n";
        $i = $this->ssa->allocReg();
        $out .= '  ' . $i . ' = sub i64 ' . $d . ", 1\n";
        $ep = $this->ssa->allocReg();
        $out .= '  ' . $ep . ' = getelementptr inbounds [4096 x i64], ptr @__mir_bt_name, i64 0, i64 ' . $i . "\n";
        $sv = $this->ssa->allocReg();
        $out .= '  ' . $sv . ' = ptrtoint ptr ' . $this->strLitId($this->pool->intern($disp)) . " to i64\n";
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
        if ($n->kind === Node::KIND_METHOD_CALL && $n->method === 'throw') {
            return true;
        }
        foreach (\Compile\Mir\Walk::children($n) as $c) {
            if ($this->scanGenThrow($c)) { return true; }
        }
        return false;
    }

    /** Prop names used as a RAW array base (`$this->p[...]`, `foreach ($this->p)`)
     *  — the SPL backing-slot pattern; never box (array-access reads the raw buffer). */
    private array $cellPropArrayBase = [];

    /**
     * Property NAMES a storable reference is taken to in this module
     * (`[&$o->p]`). Such a slot is promoted in place on the first `&`
     * ({@see EmitLlvmObjects::emitRefCell}): it holds `cell(REF, box)` from
     * then on, so a store of that name tests the slot's tag and writes through
     * the box, and the class drop gives the slot's count on the box back. By
     * NAME, not by class: the object may be typed as a parent or a child of the
     * class the `&` named, and the runtime tag is the real answer anyway.
     *
     * @var array<string, bool>
     */
    private array $refCellPropNames = [];

    /** Fill {@see $refCellPropNames} from one function body. */
    private function collectRefCellPropNames(Node $n): void
    {
        if ($n->kind === Node::KIND_REF_CELL) {
            $kids = \Compile\Mir\Walk::children($n);
            if ($kids[0]->kind === Node::KIND_PROPERTY_ACCESS) {
                $this->refCellPropNames[$this->asPropAccess($kids[0])->property] = true;
            }
            return;
        }
        foreach (\Compile\Mir\Walk::children($n) as $c) { $this->collectRefCellPropNames($c); }
    }

    private function asPropAccess(Node $n): \Compile\Mir\PropertyAccess_ { return $n; }

    /**
     * Prop keys whose SLOT owns one element ref per element — every store to
     * them hands the slot a reference that carries the element refs the drop
     * flavor names, so the slot's release-before-overwrite can give them back on
     * EVERY release instead of only at rc → 0 ({@see UnifiedArrayRuntime}'s
     * `_ownel_` variants).
     *
     * The count that makes this the fix, and not a double free: a buffer's
     * elements carry ONE base ref (the builder's) plus one per retain, and there
     * are exactly retains+1 releases. Every release giving back exactly one is
     * therefore balanced — while a release that gives back nothing (today, at
     * rc > 0) strands the ref its retain took. `$m = build(); $h->set($m);` in a
     * loop leaked every key and value that way: `set`'s retain co-owned them and
     * its release-before-overwrite ran at rc 2 → 1, dropping nothing.
     *
     * @var array<string, string> key => the drop flavor proven for it
     */
    private array $propOwnElem = [];

    /** A site asked for `get_object_vars`' class-table walk, so the module needs
     *  the one shared body ({@see EmitLlvmBuiltins::emitObjectVarsFn}). */
    private bool $needsObjectVarsFn = false;

    /** A site asked for the erased `get_class()` body. */
    private bool $needsGetClassFn = false;

    /** A site asked for the unknown-class dynamic-property-bag body. */
    private bool $needsBagOfFn = false;

    /** A site asked for the erased shallow-boxing body. */
    private bool $needsBoxUnknownFn = false;

    /** Key channels (`cell` / `int` / `str`) an erased `$x[$k]` site used;
     *  one shared body each ({@see EmitLlvmArrays::emitErasedIndexFns}). */
    /** @var array<string, true> */
    private array $eidxNeeded = [];

    /** Argument SHAPES a `new $cls(...)` site used; one shared comparison
     *  chain each ({@see EmitLlvmObjects::emitNewDynFns}). */
    /** @var array<string, \Compile\Mir\NewDynShape> */
    private array $newDynNeeded = [];

    /**
     * Erased-interface dispatchers this module needs
     * ({@see EmitLlvmObjects::emitErasedIfaceCall}), keyed `iface|method|argc`.
     *
     * THREE PARALLEL ARRAYS, not one array of triples: a keyed store of a
     * LIST into an array property, written from a trait, did not survive to
     * the reader — the registry read back empty and every site called a
     * dispatcher whose body was never emitted. Same shape as the note on the
     * builtin-ownership prober.
     *
     * @var array<string, string>
     */
    private array $erasedIfaceIface = [];
    /** @var array<string, string> */
    private array $erasedIfaceMethod = [];
    /** @var array<string, int> */
    private array $erasedIfaceArgc = [];

    /**
     * Out-of-line virtual dispatchers: shape key → symbol, and the bodies
     * themselves ({@see EmitLlvmObjects::emitVirtualDispatch}). A STRING
     * accumulator, not a registry of structures — the body is built at first
     * sight, so nothing has to be stored and rebuilt later.
     * @var array<string, string>
     */
    private array $vdSyms = [];
    /**
     * What tells THIS module's specialized helpers apart from another
     * module's of the same name.
     *
     * The out-lined dispatchers were `internal`, and correctly so: each body
     * is specialized from this module's class table, so a shared symbol would
     * let the linker fold in another module's. But `internal` is FILE-LOCAL,
     * which means the splitter has to copy every one of them into every part
     * that reaches one — and they are reached from everywhere. Measured on
     * symfony-demo T5: parts p0 and p1 were 155 MB each and shared 859 of
     * their 864 internal bodies, 857 of which were `__mir_vdisp_*` and
     * `__mir_object_vars`. Out-lining them stopped being a saving and became
     * a hub that pulls itself into 15 parts.
     *
     * A name derived from the module removes the reason for `internal`: two
     * modules cannot collide, so the bodies can be `linkonce_odr` and the
     * splitter partitions them like any other coalesced function — ONE copy,
     * pinned through the optimizer by `@llvm.compiler.used`.
     */
    private string $modToken = '';

    /** A module-local helper's symbol, tagged with {@see $modToken}. */
    protected function mirHelperSym(string $base): string
    {
        return $base . '_m' . $this->modToken;
    }

    private string $vdExtraBodies = '';

    /** The module's shared erased-receiver clone ({@see EmitLlvmObjects::emitCloneErased}), once emitted. */
    private string $cloneErasedSym = '';

    /** shape key => the shared erased-dynamic-method chain's symbol. */
    /** @var array<string, string> */
    private array $dynmSyms = [];

    /** Bodies for {@see EmitLlvmObjects::dynmChainFn}, flushed with the others. */
    private string $dynmExtraBodies = '';

    /** struct class|mode => its compare helper ({@see EmitLlvmExpr::structCmpSym}). */
    /** @var array<string, string> */
    private array $scmpSyms = [];

    /** Bodies of those helpers, flushed with the other lazy helpers. */
    private string $scmpExtraBodies = '';

    /** callee|arg-kind key => the uniform `i64 (i64…)` thunk's symbol.
     *  {@see EmitLlvmCalls::dynfThunk} */
    /** @var array<string, string> */
    private array $dynfThunks = [];

    /** candidate-set key => [row-array symbol, row count] for the dynamic
     *  function-name TABLE that replaced that set's strcmp chain. */
    /** @var array<string, array{string, int}> */
    private array $dynfTables = [];

    /** Thunk bodies + row globals for the table path, flushed with the others. */
    private string $dynfExtraBodies = '';

    /** {@see EmitLlvmArrays::litConstTable} globals, flushed with the helper bodies. */
    private string $litTableBodies = '';

    /** {@see btPush}: function => the base line its `@.btl.` global holds.
     *  @var array<string, int> */
    private array $btBaseLine = [];

    /** {@see EmitLlvmObjects::dynScopeRelated}: scope class => [symbol, n].
     *  @var array<string, array{string, int}> */
    private array $dynScopeRelTables = [];

    /** {@see EmitLlvmObjects::newDynTable}, per module.
     *  @var array{string, int, array<string, bool>}|null */
    private ?array $newDynTableCache = null;

    /** {@see EmitLlvmObjects::classlessMethodCandidates} per argc, per module.
     *  @var array<int, array<string, Type>> */
    private array $classlessCandidatesMemo = [];

    private int $litTableCount = 0;
    /** @var array<string, string> class name => its instance template symbol, '' = none ({@see EmitLlvmObjects::objInitTemplate}) */
    private array $objTemplates = [];
    /** `litTableBodies` is already in the output: a template minted now would never be defined. */
    private bool $litTablesFlushed = false;

    /** The module already carries one copy of `__mc_dynf_lookup`. */
    private bool $dynfLookupEmitted = false;

    /** A `require`/`include` site asked for the include-slot chain, so the module
     *  needs the one shared body ({@see EmitLlvmBuiltins::emitInclResolveFn}). */
    private bool $needsInclResolveFn = false;

    /**
     * Keys disqualified from the above: a store whose reference does NOT carry
     * element refs the drop would give back — a `_buf` / repr-mode retain, a
     * store with no retain type at all, a non-array value. One such store and
     * the slot keeps today's drop-at-zero, because a release that gives back a
     * ref it never held is an over-release on a LIVE buffer.
     *
     * @var array<string, bool>
     */
    private array $propOwnElemVeto = [];

    /** {@see \Compile\Mir\Module::$isLibraryModule} */
    private bool $moduleIsLibrary = false;

    /** @var array<string, bool> classes `clone`d somewhere in this module. */
    private array $clonedClasses = [];

    /** A `clone` whose receiver class is erased — it may be ANY class, so no
     *  class can be proven un-cloned. */
    private bool $cloneClassUnknown = false;

    /**
     * Module-wide property-store facts, one walk: which slots own one element
     * ref per element ({@see markPropOwnElem}), which classes are cloned, and
     * which properties are a raw array base ({@see $cellPropArrayBase}).
     */
    private function scanCellPropStores(Node $n): void
    {
        if ($n->kind === Node::KIND_STORE_PROPERTY) {
            // Key by the DECLARING class (+ a bare-name global fallback when the
            // receiver is erased), so a same-named property in an unrelated class
            // cannot poison this slot's ownership facts.
            $this->markPropOwnElem($n, $this->cellPropKey($n->object->type->class ?? '', $n->property));
        }
        // `clone` COPIES an array property with __mir_array_copy, which co-owns
        // elements in REPR mode — i.e. nothing at all for a concrete, unstamped
        // buffer. A drop deepened past the slot's own type would then give back a
        // ref the copy never took, on elements the source still holds. Record the
        // cloned class so {@see classDropFlavor} can refuse the widening.
        if ($n->kind === Node::KIND_CLONE) {
            $cc = $this->cloneObjectClass($n);
            if ($cc === '') { $this->cloneClassUnknown = true; }
            else { $this->clonedClasses[$cc] = true; }
        }
        $base = $this->cellPropArrayBaseKey($n);
        if ($base !== null) { $this->cellPropArrayBase[$base] = true; }
        foreach (\Compile\Mir\Walk::children($n) as $c) {
            $this->scanCellPropStores($c);
        }
    }

    /**
     * Whether `$x = $obj->prop` takes a REFERENCE on an ARRAY it reads into a
     * RAW slot — the snapshot whose element refs the local's release pairs
     * ({@see EmitLlvmMemory::ownElemPairFlavor}). Character-for-character the
     * `$aliasArrayProp` gate of {@see EmitLlvmLocals::emitStoreLocal}; a
     * box-back store boxes (or rebuilds) the array into a cell instead.
     */
    private function storeLocalRetainsProp(Node $store, Node $pa): bool
    {
        if ($store->type->kind === Type::KIND_CELL && $pa->type->kind !== Type::KIND_CELL) {
            // A SUPERGLOBAL is a module cell in every scope, and its store
            // takes its own reference — an array is copied and the copy adopts
            // the elements ({@see EmitLlvmLocals::globalCellOwnIr}).
            return $pa->type->isArray() && $this->isSuperglobalName($store->name);
        }
        if (\Compile\Mir\AliasOwn::propReadCoOwns($pa)) { return true; }
        return $pa->type->isArray()
            || $this->slotIsArrayHinted($pa->object, $pa->property, $pa->type);
    }

    /**
     * A class this module actually stores an UNDECLARED property on gets a
     * dynamic-property bag.
     *
     * php 8.2+ only DEPRECATES the creation of a dynamic property; the write
     * still happens. Without a bag the store fell through to the fixed-slot
     * path and wrote through an offset the class does not have — `$o->dyn = 5`
     * on a plain class SIGSEGVed, and `isset($o->nope)` answered true where php
     * answers false.
     *
     * Granted from the STORE, so an object pays the extra word only where the
     * program needs one. Descendants inherit it, the rule
     * `#[AllowDynamicProperties]` already follows — a subclass without the slot
     * would take the parent's offset and land in one of its own properties. A
     * prelude / extern class is left alone (its layout is a published fact this
     * module does not get to change) and a `#[Struct]` has no header to hold a
     * bag at all.
     *
     * ⚠ HERE, not in LowerFromAst: the receiver's CLASS comes from inference,
     * which has not run when lowering ends — the same scan there matched
     * nothing at all. Layout is read during emission, which is after this.
     */
    private function grantBagsForDynamicStores(\Compile\Mir\Module $module): void
    {
        if (!\Compile\Debug::$dynPropBag) { return; }
        $want = [];
        foreach ($module->functions as $fn) {
            $this->scanDynamicPropStores($fn->body, $want);
        }
        if ($want === [] || $module->isLibraryModule) { return; }
        // ⚠ The bag sits at the END of a class's own layout, which is the MIDDLE
        // of every subclass's — a base-typed pointer writing it would land in a
        // property the child declares. Granting one to `Compile\Mir\Node` (the
        // compiler's own base node) built a compiler that SIGSEGVed on hello
        // world. So the whole HIERARCHY takes the bag together, at ONE offset:
        // the deepest layoutEnd in it, which every member then agrees on.
        //
        // A library module is skipped outright: its class layouts go into a
        // `.sig` that other modules compile against, and this offset is not in
        // it.
        foreach ($want as $root => $ignored) {
            $family = [];
            foreach ($this->classes as $name => $cd) {
                $cur = $name;
                $guard = 0;
                while ($cur !== '' && $guard < 256) {
                    if ($cur === $root) { $family[$name] = $cd; break; }
                    $cur = isset($this->classes[$cur])
                    ? ($this->classes[$cur]->parent ?? '') : '';
                    $guard = $guard + 1;
                }
            }
            // Someone in the family already has a bag (an inherited
            // #[AllowDynamicProperties]): its layout is already published to the
            // rest of this module, so leave the family exactly as it is.
            $taken = false;
            foreach ($family as $cd) {
                if ($cd->usesBag()) { $taken = true; }
            }
            if ($taken || $family === []) { continue; }
            $off = 0;
            foreach ($family as $cd) {
                $end = $cd->instanceSize();   // no bag yet, so this IS layoutEnd
                if ($end > $off) { $off = $end; }
            }
            if (\getenv('MANTICORE_BAG_TRACE') !== false) {
                \error_log('BAG-GRANT ' . $root . ' family=' . (string)\count($family)
                    . ' offset=' . (string)$off);
            }
            foreach ($family as $cd) {
                $cd->bagOffsetFixed = $off;
                $cd->hasBag = true;
            }
        }
    }

    /**
     * Does a SUBCLASS of `$cls` declare `$prop`?
     *
     * Then the store is not a dynamic property at all — it is a write through
     * an imprecise static type (`$node->name = …` where `$node` is typed as the
     * base `Compile\Mir\Node` and is really a `LoadLocal`). Routing that into a
     * bag would hide the value from every read through the concrete class, and
     * it is what first proposed a bag for the compiler's own node hierarchy.
     */
    private function subclassDeclares(string $cls, string $prop): bool
    {
        foreach ($this->classes as $name => $other) {
            if ($name === $cls) { continue; }
            if ($other->propertyOffset($prop) === -1) { continue; }
            $cur = $other->parent ?? '';
            $guard = 0;
            while ($cur !== '' && $guard < 256) {
                if ($cur === $cls) { return true; }
                $cur = isset($this->classes[$cur])
                    ? ($this->classes[$cur]->parent ?? '') : '';
                $guard = $guard + 1;
            }
        }
        return false;
    }
    /**
     * A class that defines `__set` is NOT a dynamic-property case: php routes
     * the undeclared store to the magic method, and giving it a bag made
     * `__get`/`__set` stop firing (magic_get_set, magic_isset_unset_erased,
     * magic_get_erased_receiver).
     * @param array<string,bool> $want classes seen taking an undeclared store
     */
    private function scanDynamicPropStores(Node $n, array &$want): void
    {
        if ($n->kind === Node::KIND_STORE_PROPERTY) {
            $cls = $n->object->type->class ?? '';
            if ($cls !== '' && isset($this->classes[$cls])) {
                $cd = $this->classes[$cls];
                if (!$cd->isStruct && !$cd->isExternClass && !$cd->isPreludeClass
                    && !$cd->usesBag() && $cd->propertyOffset($n->property) === -1
                    && !$this->subclassDeclares($cls, $n->property)
                    && $this->resolveMethodClass($cls, "__set") === "") {
                    $want[$cls] = true;
                }
            }
        }
        foreach (\Compile\Mir\Walk::children($n) as $c) {
            $this->scanDynamicPropStores($c, $want);
        }
    }

    /**
     * Judge ONE store into a property slot: does the reference it hands the slot
     * carry the element refs the slot's drop would give back?
     *
     * The question is a pure TYPE one — {@see EmitLlvmMemory::arrayRetainFlavor}
     * answers exactly what the store's retain co-owns, and it answers the same
     * for a MOVE (an owned literal / call return transfers its +1 without a
     * retain, and that reference carries the builder's base element refs). What
     * must not slip through is a reference with NO element refs behind it: a
     * `*buf` or repr-mode flavor, an unretainable value, or a store whose retain
     * type disagrees with the flavor the drop will use.
     *
     * ⚠ A veto is module-wide and permanent — one bad store anywhere and the
     * slot keeps the leak. That is the safe direction, and it is the direction
     * every other conservative gate here already takes.
     */
    private function markPropOwnElem(\Compile\Mir\StoreProperty $n, string $key): void
    {
        $t = $this->propStoreRetainType($n);
        $drop = $t === null ? '' : $this->discardReleaseFlavor($t);
        // The flavor the store's retain ACTUALLY co-owns at. For a declared
        // `Nd[]` slot it equals $drop; for a bare `array` one it does not —
        // {@see EmitLlvmMemory::arrayRetainFlavor} reads the informative side, so
        // the store retains at `vecobj` while the slot's own type says only `vec`
        // (or, once a bare `array` has erased to `unknown`, says nothing at all).
        // That disagreement WAS the veto, and the veto was the leak.
        $retain = $t === null ? '' : $this->arrayRetainFlavor($n->value, $t);
        // An EMPTY array literal — every `public array $x = []` default and every
        // re-seed — hands the slot a buffer with no elements. It can neither
        // prove the flavor nor disprove it, and vetoing on it would disqualify
        // essentially every property before its first real store.
        if ($n->value->type->isArray() && $this->isEmptyArrayLit($n->value)) { return; }
        $ok = $t !== null
            && $n->value->type->isArray()
            && $this->isOwnElemFlavor($retain)
            && ($retain === $drop || $this->isErasedSlotFlavor($drop));
        if (!$ok) {
            $this->propOwnElemVeto[$key] = true;
            // An ERASED receiver names no class, so the veto has to cover every
            // class declaring the name — the same fallback {@see cellPropKey}
            // already relies on.
            if (($n->object->type->class ?? '') === '') { $this->propOwnElemVeto[$n->property] = true; }
            return;
        }
        if (isset($this->propOwnElem[$key]) && $this->propOwnElem[$key] !== $retain) {
            $this->propOwnElemVeto[$key] = true;
            return;
        }
        $this->propOwnElem[$key] = $retain;
    }

    /** The class a `clone` names, '' when the receiver's type is erased. Read
     *  through a Clone_-typed param so `->object` resolves the right field
     *  offset under the self-host. */
    private function cloneObjectClass(\Compile\Mir\Clone_ $n): string
    {
        return $n->object->type->class ?? '';
    }

    /** Whether a class may be `clone`d in this module — conservatively true when
     *  any clone site has an erased receiver. */
    private function classMayBeCloned(string $class): bool
    {
        if ($this->cloneClassUnknown) { return true; }
        return isset($this->clonedClasses[$class]);
    }

    /** A slot flavor that claims NOTHING about the elements behind it: the two
     *  repr-dispatching array flavors, which read ownership off the buffer's own
     *  bits, and '' — what a bare `array` erased to `unknown` leaves, which drops
     *  the slot from the class drop body altogether. A proven per-store flavor
     *  may replace one of these; it may never deepen a concrete one it disagrees
     *  with. */
    private function isErasedSlotFlavor(string $flavor): bool
    {
        return $flavor === 'vec' || $flavor === 'assoc' || $flavor === '';
    }

    /** The flavors that name element refs a release can give back. `vec`/`assoc`
     *  (repr mode) read ownership off the BUFFER's own bits, which a per-slot
     *  claim cannot speak for; `*buf` holds none at all. */
    private function isOwnElemFlavor(string $flavor): bool
    {
        return $flavor === 'vecstr' || $flavor === 'assocstr'
            || $flavor === 'vecobj' || $flavor === 'assocobj'
            || $flavor === 'veccell' || $flavor === 'assoccell';
    }

    // Generator frame layout:
    //   resume_fn@0, state@8, current@16, key@24, nextkey@32,
    //   sent@40, retval@48, locals@56+
    // state: 0 = not started, k = suspended at yield k, -1 = finished.
    private const GEN_HEADER = 56;

    /** Count `yield` nodes in a generator body (state-machine arity). */
    private function countYields(Node $n): int
    {
        $c = $n->kind === Node::KIND_YIELD ? 1 : 0;
        foreach (\Compile\Mir\Walk::children($n) as $ch) {
            $c = $c + $this->countYields($ch);
        }
        return $c;
    }

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
    /** @var array<string, string[]> {@see Module::$interfaceAncestors} */
    private array $interfaceAncestors = [];

    private function classImplements(string $class, string $iface): bool
    {
        $key = $class . '|' . $iface;
        if (isset($this->classImplementsCache[$key])) {
            return $this->classImplementsCache[$key];
        }
        $seen = [];
        $stack = [$class];
        while ($stack !== []) {
            $c = \array_pop($stack);
            if ($c === '' || isset($seen[$c])) { continue; }
            $seen[$c] = true;
            if ($c === $iface) {
                $this->classImplementsCache[$key] = true;
                return true;
            }
            $cd = $this->classes[$c] ?? null;
            if ($cd === null) {
                foreach ($this->interfaceAncestors[$c] ?? [] as $ia) { $stack[] = $ia; }
                continue;
            }
            if ($cd->parent !== '') { $stack[] = $cd->parent; }
            foreach ($cd->interfaces as $i) { $stack[] = $i; }
        }
        $this->classImplementsCache[$key] = false;
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

    private int $iterCounter = 0;

    /**
     * B5 PGO metrics. Counter indices into the @__prof array:
     * 0 str_alloc, 1 str_retain, 2 str_release, 3 rc_retain (obj/vec),
     * 4 rc_release (obj/vec), 5 assoc_retain, 6 assoc_release,
     * 7-13 retain by source category, 14-15 array-alloc traffic,
     * 16-23 pool traffic (alloc/hit/miss/free/bypass + obj/bucket/cell).
     * The names — and the array's length — live in one place:
     * {@see EmitLlvmModule::profileRuntime}.
     * Emitted only under `MANTICORE_PROFILE=1`; a no-op string otherwise so
     * production IR is byte-identical.
     */
    private function profBump(int $idx): string
    {
        if (!\Compile\Debug::$profile && !\Compile\Debug::$allocTrace) { return ''; }
        return '  call void @__prof_bump(i64 ' . (string)$idx . ")\n";
    }

    /**
     * Add `$nReg` (an i64 register or literal) to counter `$idx` — the BYTE
     * counters. Same gating as {@see profBump}, so production IR is unchanged.
     */
    private function profAdd(int $idx, string $nReg): string
    {
        if (!\Compile\Debug::$profile && !\Compile\Debug::$allocTrace) { return ''; }
        return '  call void @__prof_add(i64 ' . (string)$idx . ', i64 ' . $nReg . ")\n";
    }

    /**
     * Per-CLASS ALLOCATION tally. The index is a literal — the producing class is
     * static at the allocation site. There is no free half: the free site holds
     * only the descriptor's hashed class_id, and mapping that back to the dense
     * index would need a switch over every class, i.e. code per class again.
     */
    private function profClass(string $classIdReg): string
    {
        if (!\Compile\Debug::$profile && !\Compile\Debug::$allocTrace) { return ''; }
        return '  call void @__prof_class(i64 ' . $classIdReg . ")\n";
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
        // `Enum::from($v)` synthesizes a `throw ValueError` on a miss — the base
        // landing pad must be set up so an uncaught miss exits 255, not longjmp
        // to garbage. (tryFrom never throws.)
        if ($n->kind === Node::KIND_STATIC_CALL) {
            if ($n->method === 'from' && isset($this->enums[$n->class])) { return true; }
        }
        foreach (\Compile\Mir\Walk::children($n) as $c) {
            if ($this->scanUsesExceptions($c)) { return true; }
        }
        return false;
    }

    /**
     * Collect {@see OwnershipFlow}'s registrations (`own_local`; `own_local_b`
     * when some source of the name borrows) into {@see FunctionEmitFrame::$ownLocals}.
     * A by-ref param (its slot holds the caller's ADDRESS) and a global-backed
     * name (its storage is a module cell that outlives the call) stay out: a
     * drop of either releases what this frame does not own.
     */
    private function collectOwnLocals(Node $n): void
    {
        if ($n->kind === Node::KIND_MEMORY_OP) {
            $mo = $n;
            if (($mo->op === 'own_local' || $mo->op === 'own_local_b')
                && $mo->target !== null && $mo->target->kind === Node::KIND_LOAD_LOCAL) {
                if (isset($this->locals->refLocals[$mo->target->name])) { return; }
                if (isset($this->locals->globalBacked[$mo->target->name])) { return; }
                // The MemoryOp node, not its flavor string: a node handle
                // survives the self-host where a short assoc string once did not.
                $this->frame->ownLocals[$mo->target->name] = $mo;
                if ($mo->op === 'own_local_b') { $this->frame->ownBorrowed[$mo->target->name] = true; }
            }
            return;
        }
        foreach (\Compile\Mir\Walk::children($n) as $c) { $this->collectOwnLocals($c); }
    }

    /** Mark the array local under an `$a[$k]` element as mutated (its element may
     *  be written through a reference). No-op for non-element / non-array-local. */
    private function markVecElemBase(Node $a): void
    {
        if ($a->kind !== Node::KIND_ARRAY_ACCESS) { return; }
        $arr = $a->array;
        if ($arr->kind === Node::KIND_LOAD_LOCAL && $arr->type->isArray()) {
            $this->frame->mutatedVecLocals[$arr->name] = true;
        }
    }

    private string $lastValue = '0';
    private string $lastValueType = 'i64';

    /**
     * Emit one node. The node picks its own visit method (double dispatch) —
     * this used to be a chain of up to 64 `kind ===` tests walked on every node.
     */
    private function emitNode(Node $n): string
    {
        if ($this->irCensus) { return $this->emitNodeCensus($n); }
        $out = $n->accept($this);
        if ($this->cellGuard) { $this->markCellCalleeResult($n); }
        return $out;
    }

    /**
     * `MANTICORE_IR_CENSUS=1` (with MANTICORE_STATS=1): the IR bytes each MIR
     * construct emits ITSELF — its whole output minus what its children emitted —
     * summed over the module, a direct call split by callee (an inlined builtin
     * is a call). Answers which constructs the IR volume comes from, which the
     * finished `.ll` cannot: by then every construct is instructions.
     */
    private bool $irCensus = false;
    /** @var array<string, int> */
    private array $censusBytes = [];
    /** @var array<string, int> */
    private array $censusCount = [];
    /** @var int[] */
    private array $censusChild = [];
    /** @var array<string, int> */
    private array $censusMax = [];
    /** @var array<string, string> */
    private array $censusMaxFn = [];
    /** @var array<string, int> bytes in instances over 2 KB */
    private array $censusBig = [];

    private function emitNodeCensus(Node $n): string
    {
        $this->censusChild[] = 0;
        $out = $n->accept($this);
        if ($this->cellGuard) { $this->markCellCalleeResult($n); }
        $kids = (int)\array_pop($this->censusChild);
        $len = \strlen($out);
        $key = $n->kind;
        if ($n instanceof \Compile\Mir\Call) { $key = 'call:' . $this->censusCallee($n); }
        $this->censusBytes[$key] = ($this->censusBytes[$key] ?? 0) + $len - $kids;
        $this->censusCount[$key] = ($this->censusCount[$key] ?? 0) + 1;
        $self = $len - $kids;
        if ($n instanceof \Compile\Mir\Foreach_) {
            $bk = $this->censusForeachBaseKind($n);
            $tk = ($bk === Type::KIND_CELL || $bk === Type::KIND_UNKNOWN) ? 'foreach.erased(total)' : 'foreach.typed(total)';
            $this->censusBytes[$tk] = ($this->censusBytes[$tk] ?? 0) + $len;
            $this->censusCount[$tk] = ($this->censusCount[$tk] ?? 0) + 1;
            if ($tk === 'foreach.erased(total)') {
                $bn = 'fe.erased.base ' . $this->censusForeachBaseNode($n) . ' in ' . $this->frame->name;
                $this->censusBytes[$bn] = ($this->censusBytes[$bn] ?? 0) + $len;
                $this->censusCount[$bn] = ($this->censusCount[$bn] ?? 0) + 1;
            }
        }
        if ($self > ($this->censusMax[$key] ?? 0)) { $this->censusMax[$key] = $self; $this->censusMaxFn[$key] = $this->frame->name; }
        if ($self > 2000) { $this->censusBig[$key] = ($this->censusBig[$key] ?? 0) + $self; }
        $d = \count($this->censusChild);
        if ($d > 0) { $this->censusChild[$d - 1] = $this->censusChild[$d - 1] + $len; }
        return $out;
    }

    private function censusForeachBaseKind(\Compile\Mir\Foreach_ $f): string { return $f->array->type->kind; }
    private function censusForeachBaseNode(\Compile\Mir\Foreach_ $f): string
    {
        $a = $f->array;
        $d = $a->kind;
        if ($a instanceof \Compile\Mir\LoadLocal) { $d .= ':' . $a->name; }
        if ($a instanceof \Compile\Mir\PropertyAccess_) { $d .= ':' . $a->property; }
        if ($a instanceof \Compile\Mir\Call) { $d .= ':' . $a->function; }
        return $d;
    }

    private function censusCallee(\Compile\Mir\Call $c): string { return $c->function; }

    private function reportIrCensus(int $bodyBytes): void
    {
        if (!$this->irCensus) { return; }
        $bytes = $this->censusBytes;
        \arsort($bytes);
        $sum = 0;
        foreach ($bytes as $b) { $sum = $sum + $b; }
        \Compile\Stats::line('census: node-attributed ' . (string)$sum . ' of ' . (string)$bodyBytes
            . ' body bytes (rest = prologues/epilogues/helpers)');
        $i = 0;
        foreach ($bytes as $k => $b) {
            $c = $this->censusCount[$k] ?? 1;
            \Compile\Stats::line('census: ' . \str_pad((string)$b, 10, ' ', \STR_PAD_LEFT)
                . ' B ' . \str_pad((string)$c, 8, ' ', \STR_PAD_LEFT) . ' x '
                . \str_pad((string)\intdiv($b, $c > 0 ? $c : 1), 7, ' ', \STR_PAD_LEFT) . ' B/each  ' . (string)$k
                . '  | >2KB ' . (string)($this->censusBig[$k] ?? 0)
                . ' | max ' . (string)($this->censusMax[$k] ?? 0) . ' in ' . ($this->censusMaxFn[$k] ?? ''));
            $i = $i + 1;
            if ($i >= 400) { break; }
        }
    }

    /**
     * `$l <op> $r` over two cells into `$res`, with the two-INLINE-int case done
     * in place (48-bit payloads, a result that stays inline) and only the rest
     * calling `__manticore_tagged_<op>`: the loop counters of erased code —
     * `$i + $itemsCount` in php-cs-fixer's insertSlices — paid a call per step.
     */
    private function taggedIntStepInline(string $op, string $l, string $r, string $res): string
    {
        $ih = (string)((1 << 48) | \PHP_INT_MIN | 0x7FF0000000000000);
        $a = fn (): string => $this->ssa->allocReg();
        $lh = $a(); $rh = $a(); $li = $a(); $ri = $a(); $both = $a();
        $ls = $a(); $lv = $a(); $rs = $a(); $rv = $a(); $sum = $a(); $ss = $a(); $sb = $a(); $fit = $a(); $ok = $a();
        $pl = $a(); $fw = $a(); $sw = $a();
        $fastL = $this->ssa->allocLabel('tint.fast');
        $slowL = $this->ssa->allocLabel('tint.slow');
        $endL = $this->ssa->allocLabel('tint.end');
        $out  = '  ' . $lh . ' = and i64 ' . $l . ", -281474976710656\n";
        $out .= '  ' . $rh . ' = and i64 ' . $r . ", -281474976710656\n";
        $out .= '  ' . $li . ' = icmp eq i64 ' . $lh . ', ' . $ih . "\n";
        $out .= '  ' . $ri . ' = icmp eq i64 ' . $rh . ', ' . $ih . "\n";
        $out .= '  ' . $both . ' = and i1 ' . $li . ', ' . $ri . "\n";
        $out .= '  ' . $ls . ' = shl i64 ' . $l . ", 16\n  " . $lv . ' = ashr i64 ' . $ls . ", 16\n";
        $out .= '  ' . $rs . ' = shl i64 ' . $r . ", 16\n  " . $rv . ' = ashr i64 ' . $rs . ", 16\n";
        $out .= '  ' . $sum . ' = ' . $op . ' i64 ' . $lv . ', ' . $rv . "\n";
        $out .= '  ' . $ss . ' = shl i64 ' . $sum . ", 16\n  " . $sb . ' = ashr i64 ' . $ss . ", 16\n";
        $out .= '  ' . $fit . ' = icmp eq i64 ' . $sb . ', ' . $sum . "\n";
        $out .= '  ' . $ok . ' = and i1 ' . $both . ', ' . $fit . "\n";
        $out .= '  br i1 ' . $ok . ', label %' . $fastL . ', label %' . $slowL . "\n";
        $out .= $fastL . ":\n";
        $out .= '  ' . $pl . ' = and i64 ' . $sum . ', ' . (string)\Compile\MemoryAbi::CELL_PAYLOAD_MASK . "\n";
        $out .= '  ' . $fw . ' = or i64 ' . $pl . ', ' . $ih . "\n";
        $out .= '  br label %' . $endL . "\n";
        $out .= $slowL . ":\n";
        $out .= '  ' . $sw . ' = call i64 @__manticore_tagged_' . $op . '(i64 ' . $l . ', i64 ' . $r . ")\n";
        $out .= '  br label %' . $endL . "\n";
        $out .= $endL . ":\n";
        $out .= '  ' . $res . ' = phi i64 [ ' . $fw . ', %' . $fastL . ' ], [ ' . $sw . ', %' . $slowL . " ]\n";
        return $out;
    }

    /** `$left <op> $right` where the result is a numeric (int|float) cell: box
     *  both operands to tagged cells and call the runtime helper, which promotes
     *  to float iff either is float and re-boxes a cell. */
    private function emitTaggedArith(Node $left, Node $right, string $op): string
    {
        $this->rt->needsTaggedArith = true;
        $this->rt->needsTagged = true;
        $this->rt->needsTaggedToInt = true;
        $this->rt->needsStrtol = true;
        $this->rt->needsTaggedToFloat = true;
        $this->rt->needsStrtod = true;
        $out = $this->emitNode($left);
        $out .= $this->boxToCell($left->type);
        $l = $this->lastValue;
        $out .= $this->emitNode($right);
        $out .= $this->boxToCell($right->type);
        $r = $this->lastValue;
        $reg = $this->ssa->allocReg();
        if ($op === 'add' || $op === 'sub') {
            $out .= $this->taggedIntStepInline($op, $l, $r, $reg);
        } else {
            $out .= '  ' . $reg . ' = call i64 @__manticore_tagged_' . $op
                  . '(i64 ' . $l . ', i64 ' . $r . ")\n";
        }
        $out .= $this->dropOperandCell($left, $l);
        $out .= $this->dropOperandCell($right, $r);
        $this->lastValue = $reg;
        $this->lastValueType = 'i64';
        // The helper re-boxes on every path (int cell, float cell, promoted).
        $this->markCellBoxed($reg);
        return $out;
    }

    /**
     * The cell an operand became for a runtime helper that only READS it: a
     * fresh cell producer's +1, or the box an INT was just wrapped in (past the
     * 48-bit inline form that box is a counted heap block,
     * {@see \Compile\MemoryAbi::CELL_TAG_BIGINT}), is dead after the read. A
     * string / object / array boxed by pointer is a borrow and stays.
     */
    private function dropOperandCell(Node $n, string $cell): string
    {
        if ($this->isFreshCellTemp($n) || $n->type->kind === Type::KIND_INT) {
            return $this->rcReleaseReg($cell, 'cell');
        }
        // An element READ used right here is a one-use temp that may be the
        // owner-less box the read minted. Only the read itself: a LOCAL may hold a
        // borrowed box it reads again (a foreach value is a borrow).
        if ($n->type->kind === Type::KIND_CELL && $n->kind === Node::KIND_ARRAY_ACCESS) {
            return '  call void @__mir_cell_float_free(i64 ' . $cell . ")\n";
        }
        return '';
    }

    /**
     * Emit `$a` as a plain i64 for a builtin arg that expects an integer
     * (substr offset/length, …). A tagged-cell operand — e.g. a `strpos`
     * result carried as `int|false`, a float, a numeric string — coerces as php
     * does ({@see coerceIntArg}); the builtin handlers
     * emit args directly, bypassing the call loop's {@see unboxCellArg}.
     */
    private function emitIntArg(Node $a): string
    {
        return $this->emitNode($a) . $this->coerceIntArg($a);
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
    private function isCellBoxableArg(Type $t): bool { return \Compile\Mir\Ownership::cellBoxableKind($t); }

    /** True when `$t` is an array whose element is a concrete scalar
     *  (int/float/bool/string) — stored RAW, so it must be cellified when the
     *  array crosses into an erased (cell/unknown) parameter. */
    private function hasConcreteScalarElem(Type $t): bool
    {
        if (!$t->isArray()) { return false; }
        $e = $t->element;
        if ($e === null) { return false; }
        $ek = $e->kind;
        return $ek === Type::KIND_INT || $ek === Type::KIND_FLOAT
            || $ek === Type::KIND_BOOL || $ek === Type::KIND_STRING;
    }

    /**
     * Load an object's class_id THROUGH its header-slot-0 descriptor pointer
     * (`{ i64 class_id, ptr drop_fn }`). Leaves the id reg in
     * {@see $classIdReg} and returns the IR. Used by instanceof / virtual
     * dispatch / exception catch, which match against compile-time id sets.
     */
    /** Out-param reg for {@see emitClassIdMatch}. */
    private string $classIdMatchReg = '';

    /** Optional final path for bounded, disk-backed application emission. */
    public string $streamIrPath = '';

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
            $m = $this->ssa->allocReg();
            $out .= '  ' . $m . ' = icmp eq i64 ' . $cid . ', ' . (string)$id . "\n";
            if ($acc === '') {
                $acc = $m;
            } else {
                $or = $this->ssa->allocReg();
                $out .= '  ' . $or . ' = or i1 ' . $acc . ', ' . $m . "\n";
                $acc = $or;
            }
        }
        $this->classIdMatchReg = $acc;
        return $out;
    }

    private function emitLoadClassId(string $objpReg): string
    {
        $descI = $this->ssa->allocReg();
        $ir = '  ' . $descI . ' = load i64, ptr ' . $objpReg . "\n";
        $descP = $this->ssa->allocReg();
        $ir .= '  ' . $descP . ' = inttoptr i64 ' . $descI . " to ptr\n";
        $cid = $this->ssa->allocReg();
        $ir .= '  ' . $cid . ' = load i64, ptr ' . $descP . "\n";
        $this->classIdReg = $cid;
        return $ir;
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
        // An enum is not in the class table, so `$v instanceof Suit` on an ERASED
        // receiver had no id to match and read false — while the same test on a
        // typed receiver folds at compile time and reads true. The case
        // singletons all carry the enum's own class_id (see biEnumName), which is
        // exactly the id to accept here.
        if (isset($this->enums[$target])) { $ids[] = $this->enums[$target]->classId; }
        return $ids;
    }

    private function classIsA(string $name, string $target): bool
    {
        if ($target === 'Stringable') {
            return $this->resolveMethodClass($name, '__toString') !== '';
        }
        // `Traversable` is php's implicit base of Iterator and IteratorAggregate.
        // Neither of those is a declared interface here — they are built-ins, so
        // they are absent from `$this->classes` and the interface-parent walk
        // below never reaches Traversable from a class that names one of them on
        // its `implements`. php forbids implementing Traversable directly, so
        // those two ARE the whole membership rule.
        if ($target === 'Traversable') {
            return $this->classImplements($name, 'Iterator')
                || $this->classImplements($name, 'IteratorAggregate')
                || $this->classImplements($name, 'Traversable');
        }
        $c = $name;
        while ($c !== '') {
            // `isset`, NOT `$cd = … ?? null` + `$cd === null`: a `ClassDef|null`
            // local types as NON-null, so the native self-build leaves the slot
            // un-zeroed and the null test reads garbage — then `->interfaces`
            // walks it. Latent for as long as the stale slot happened to hold
            // something benign; adding an unrelated stdlib file shifted the
            // layout and it SIGSEGV'd on `$x instanceof $cls` over an interface.
            if (!isset($this->classes[$c])) { return false; }
            $cd = $this->classes[$c];
            if ($c === $target) { return true; }
            if (\in_array($target, $cd->interfaces, true)) { return true; }
            // A REIFIED specialization is-a its ORIGIN, and everything the origin
            // is: `Box$of$float` answers `instanceof Box`, and `Bag$of$float` —
            // whose PARENT is the specialized `Base$of$float`, so the plain chain
            // never reaches `Bag` — answers `instanceof Bag` and `instanceof Base`.
            // The origin edge is what carries PHP's identity across the layout
            // split (see LowerReify).
            if ($cd->originClass !== '' && $this->classIsA($cd->originClass, $target)) {
                return true;
            }
            $c = $cd->parent;
        }
        return false;
    }

    /**
     * Strict `cell === string`: leaves an i1 in `$eq`. The cell subject `$subj`
     * (boxed i64) equals the string cond iff its NaN tag is PTR (4) and the
     * bytes match — a non-string cell is never strictly === a string. Mirrors
     * the `string === cell` path in {@see emitCmp}.
     */
    private function emitCellStrEq(string $subj, Node $cond, string $eq): string
    {
        $this->rt->needsStrcmp = true;
        $out = $this->emitNode($cond);
        $out .= $this->coerceToPtr();
        $cp = $this->lastValue;
        $out .= $this->cellTagIr($subj);
        $tag = $this->cellTagReg;
        $isStr = $this->ssa->allocReg();
        $out .= '  ' . $isStr . ' = icmp eq i64 ' . $tag . ", 4\n";
        $cmpL = $this->ssa->allocLabel('match.streq');
        $nsL  = $this->ssa->allocLabel('match.strne');
        $jnL  = $this->ssa->allocLabel('match.strjoin');
        $out .= '  br i1 ' . $isStr . ', label %' . $cmpL . ', label %' . $nsL . "\n";
        $out .= $cmpL . ":\n";
        $payload = $this->ssa->allocReg();
        $out .= '  ' . $payload . ' = and i64 ' . $subj . ", 281474976710655\n";
        $sp = $this->ssa->allocReg();
        $out .= '  ' . $sp . ' = inttoptr i64 ' . $payload . " to ptr\n";
        $eqc = $this->ssa->allocReg();
        $out .= '  ' . $eqc . ' = call i1 @__mir_str_eq(ptr ' . $sp . ', ptr ' . $cp . ")\n";
        $out .= '  br label %' . $jnL . "\n";
        $out .= $nsL . ":\n  br label %" . $jnL . "\n";
        $out .= $jnL . ":\n";
        $out .= '  ' . $eq . ' = phi i1 [ ' . $eqc . ', %' . $cmpL . ' ], [ false, %' . $nsL . " ]\n";
        return $out;
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
            $this->flattenConcat($n->left, $ops);
            $this->flattenConcat($n->right, $ops);
            return;
        }
        $ops[] = $n;
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
            $this->rt->needsStrRc = true;
            return '  call void @__mir_rc_release_str(ptr ' . $ptr . ")\n";
        }
        if ($tk === Type::KIND_CELL || $tk === Type::KIND_UNKNOWN || $op->type->isArray()) {
            // A cell / erased operand's text is uniformly OWNED: {@see
            // EmitLlvmExpr::cellStrResultOwnIr} retains the payload it
            // aliases, the other arms mint (int_to_str, __toString) or are
            // immortal ("Array", ""), where a release is a no-op. Reading it
            // as a borrow leaked one string per `'x' . $_GET['a']` — a
            // compat handler's three superglobal reads were 255 B per request.
            // A fresh cell temp (`$cell ?? '-'`, a cell call) owes its tagged
            // word too; {@see EmitLlvmExpr::coerceToStr} parks it by this ptr.
            $this->rt->needsStrRc = true;
            $out = '  call void @__mir_rc_release_str(ptr ' . $ptr . ")\n";
            $cell = $this->ptrArgCellByReg[$ptr] ?? '';
            if ($cell !== '') {
                unset($this->ptrArgCellByReg[$ptr]);
                $out .= $this->rcReleaseReg($cell, 'cell');
            }
            return $out;
        }
        return $this->freeStrTemp($op, $ptr);
    }

    /** {@see \Compile\Mir\Ownership::tempStrOwned} */
    private function isFreshStringTemp(Node $node): bool { return $this->own->tempStrOwned($node); }

    private function isStrCharRead(Node $n): bool { return \Compile\Mir\Ownership::isStrCharRead($n); }

    /**
     * Drop the KEY temp of an array read / isset / unset, the exact mirror of
     * what the STORE paths already do ({@see EmitLlvmArrays::emitStoreElem} —
     * `concatTempRelease` on the string arm, `__mir_cell_drop` on the cell
     * arm). A store retains the key it keeps and drops its own +1; a READ keeps
     * nothing, so its +1 must die at the call — `$m["key" . $i]` and
     * `isset($m["key" . $i])` each leaked one string per lookup, which is 61 B
     * an iteration and the single largest number in the bench leak table.
     *
     * `$key` is the register already coerced for the call; `$keyIsCell` selects
     * the tag-dispatched drop. Borrowed producers (a local, a literal, an
     * element read) answer '' and stay untouched — their owner releases them.
     */
    private function keyTempRelease(Node $index, string $key, bool $keyIsCell): string
    {
        if (!$keyIsCell) { return $this->concatTempRelease($index, $key); }
        $k = $index->kind;
        if ($k !== Node::KIND_CALL && $k !== Node::KIND_METHOD_CALL
            && $k !== Node::KIND_STATIC_CALL && $k !== Node::KIND_INVOKE
            && $k !== Node::KIND_CONCAT && !\Compile\Mir\BitOp::mintsFresh($index)) { return ''; }
        $this->rt->needsRc = true;
        $this->rt->needsStrRc = true;
        return '  call void @__mir_cell_drop(i64 ' . $key . ")\n";
    }

    /**
     * Release the BASE temp of a read. `mk($i)->v` and `mkarr($i)[0]` evaluate a
     * fresh +1, take one word out of it and never free the container — the
     * mirror of {@see keyTempRelease} on the other operand, and of the receiver
     * release a method call already does ({@see \Compile\Debug::$rcRecvTemp}).
     *
     * `$reg` is the base value; `$regIsPtr` says whether it is a `ptr` register
     * (property / array reads carry one) so the release helper, which takes the
     * i64 carrier, gets a ptrtoint first.
     *
     * ⚠ Gated on a SCALAR result. A property or element that yields an object,
     * an array or a string hands it out BORROWED from the base, and freeing the
     * base would free the value the read just returned.
     */
    private function baseTempRelease(Node $base, string $reg, bool $regIsPtr, Type $resultType): string
    {
        if (!\Compile\Debug::$rcBaseTemp) { return ''; }
        $rk = $resultType->kind;
        if ($rk !== Type::KIND_INT && $rk !== Type::KIND_FLOAT && $rk !== Type::KIND_BOOL) { return ''; }
        $flavor = $this->freshRcArgFlavor($base);
        if ($flavor === '') { return ''; }
        $out = '';
        $v = $reg;
        if ($regIsPtr) {
            $v = $this->ssa->allocReg();
            $out .= '  ' . $v . ' = ptrtoint ptr ' . $reg . " to i64\n";
        }
        return $out . $this->rcReleaseReg($v, $flavor);
    }
    /** Release `$ptr` iff `$node` is a fresh owned string temp; else ''. */
    /** {@see \Compile\Mir\Ownership::tempCellOwned} */
    private function isFreshCellTemp(Node $n): bool { return $this->own->tempCellOwned($n, $this->lastCallWasBuiltin); }

    /**
     * Result reg of an {@see EmitLlvmBuiltins::emitPtrArg} operand that was a
     * fresh CELL temp -> the TAGGED word to drop once the builtin has read it.
     *
     * Keyed by the reg rather than passed along because the ~30 string builtins
     * all already hand {@see freeStrTemp} exactly that reg — so the cell twin
     * costs no per-site edit and cannot be forgotten at a new one. SSA regs
     * restart per function, so {@see EmitLlvmModule::emitFunction} clears it.
     *
     * @var array<string,string>
     */
    private array $ptrArgCellByReg = [];

    /** Forget every pending cell temp; the reg keys are only valid inside one
     *  function ({@see $ptrArgCellByReg}). */
    private function clearPtrArgCells(): void
    {
        $this->ptrArgCellByReg = [];
    }

    private function freeStrTemp(Node $node, string $ptr): string
    {
        // A fresh CELL temp: `$ptr` is only its PAYLOAD, and the payload of a
        // `string|false` may be a boxed bool whose untagged word is not an
        // address. Drop the tagged word instead — __mir_cell_drop dispatches.
        $cell = $this->ptrArgCellByReg[$ptr] ?? '';
        if ($cell !== '') {
            unset($this->ptrArgCellByReg[$ptr]);
            return $this->rcReleaseReg($cell, 'cell');
        }
        if (!$this->isFreshStringTemp($node)) { return ''; }
        $this->rt->needsStrRc = true;
        return '  call void @__mir_rc_release_str(ptr ' . $ptr . ")\n";
    }

    /**
     * Emit a MemoryOp from the plan: the arena scope's enter / leave, and
     * {@see OwnershipFlow}'s `drop` (release + zero the slot) and `own_retain`.
     * Registrations and the arena track's per-local `release` emit nothing.
     */
    private function emitMemoryOp(\Compile\Mir\MemoryOp_ $n): string
    {
        $mo = $n;
        if ($mo->op === 'arena_enter') {
            $this->rt->needsArena = true;
            $this->frame->hasArena = true;
            return "  call void @__mir_arena_enter()\n";
        }
        if ($mo->op === 'arena_leave') {
            // Fall-through exit: this runs just before the function's
            // implicit `ret`. After an explicit `return` it lands in a
            // dead block (harmless) — that path's leave is emitted by
            // emitReturn instead.
            $this->rt->needsArena = true;
            return "  call void @__mir_arena_leave()\n";
        }
        if ($mo->op === 'drop' || $mo->op === 'own_retain') {
            $slot = $this->ownOpSlot($mo);
            if ($slot === '') { return ''; }
            if ($mo->op === 'drop') {
                return $this->rcReleaseSlot($slot, $this->rcReleaseFlavor($mo))
                    . '  store i64 0, ptr ' . $slot . "\n";
            }
            return $this->ownRetainSlot($slot, $mo);
        }
        return '';
    }

    private function isNonRcScalarKind(string $k): bool { return \Compile\Mir\Ownership::isNonRcScalarKind($k); }

    private function condFlavor(Type $t): string { return $this->own->condFlavor($t); }

    /** {@see \Compile\Mir\Ownership::condOwnedTemp}, plus the conditional an
     *  erased-array return is emitting ({@see FunctionEmitFrame::$erasedCond}). */
    private function condOwnsResult(Node $n): bool
    {
        if ($this->frame->erasedCond !== null && $n === $this->frame->erasedCond) { return true; }
        return $this->own->condOwnedTemp($n);
    }

    /** The flavor every arm of an owned conditional is normalized to. */
    private function condResFlavor(Node $res): string
    {
        if ($this->frame->erasedCond !== null && $res === $this->frame->erasedCond) {
            return \Compile\Mir\Ownership::ERASED_ARR;
        }
        return $this->condFlavor($res->type);
    }

    private function elemObjFlavor(Type $el): string { return $this->own->elemObjFlavor($el); }

    /**
     * The helper-symbol suffix of a NESTED-ARRAY flavor, or '' when the flavor
     * is not one: `vecarr` → `arr`, `assocarrstr` → `arrstr`. The suffix names
     * what the element walk does to each nested array — `arr` is the
     * repr-driven release (as deep as the element describes itself) and
     * `arrstr` / `arrobj` / `arrcell` / `arrbuf` are the STATIC answers the
     * outer type already knows. One decoder, so release / retain / adopt and
     * the class-drop table cannot drift.
     */
    private function arrFlavorSuffix(string $flavor): string
    {
        if (\str_starts_with($flavor, 'vecarr')) { return \substr($flavor, 3); }
        if (\str_starts_with($flavor, 'assocarr')) { return \substr($flavor, 5); }
        return '';
    }

    /** {@see \Compile\Mir\Ownership::releaseFlavor} */
    private function discardReleaseFlavor(Type $t): string { return $this->own->releaseFlavor($t); }

    /**
     * The flavor with which an ELEMENT SLOT of `$arrType` releases the value an
     * overwrite or an `unset` takes off it — the array analogue of
     * {@see EmitLlvmObjects::propSlotDropsOldValue}, '' when nothing may drop.
     *
     * The slot owns what it holds: every element store retains the value it
     * writes ({@see EmitLlvmArrays::emitStoreElemValue}), so the reference the
     * slot loses is one the slot itself took. That is the whole argument, and it
     * is why the answer is read off the CONTAINER's element type and never off
     * the value being written — depth follows the DESTINATION.
     *
     * Refused, deliberately:
     *  - a base that is not a proven vec/assoc (a `cell` / erased base carries
     *    no element type we may trust);
     *  - an UNKNOWN element — the erased channel is not self-describing, and
     *    the repr nibble it does carry is stamped only by the stores that erase;
     *
     * A CELL element drops too. It was refused while `cell` was a static claim
     * rather than a runtime guarantee; the value channel is verified since
     * (W4), every cell store retains what it boxes, and the buffer's own
     * release already drops every element it holds — so refusing the
     * overwrite only stranded the displaced value: `$this->__data[$i] = $v`
     * in SplFixedArray::offsetSet kept every token php-cs-fixer replaced. The
     * word is decoded by the buffer's hint first ({@see elemSlotReleaseIr}),
     * so a raw word in a raw-hinted buffer is never read as a cell.
     * (`$cellElemOwned` — a superglobal base — predates this and is kept for
     * its callers.)
     */
    private function elemSlotDropFlavor(Type $arrType, bool $cellElemOwned = false): string
    {
        if (!\Compile\Debug::$rcElemSlotDrop) { return ''; }
        // A CELL base (a superglobal, a global two kinds share) holds an array
        // whose element representation only its buffer knows: the old word is
        // decoded by the buffer's HINT ({@see EmitLlvmArrays::elemSlotReleaseIr}),
        // and an unstamped buffer's raw word is a no-op to the cell drop.
        if ($arrType->kind === Type::KIND_CELL) { return 'cell'; }
        if (!$arrType->isVec() && !$arrType->isAssoc()) { return ''; }
        $el = $arrType->element;
        if ($el === null) { return ''; }
        $k = $el->kind;
        if ($k === Type::KIND_UNKNOWN) { return ''; }
        if ($k === Type::KIND_CELL) { return 'cell'; }
        // A closure slot drops through the buffer's own ownership record
        // (`__mir_array_clo_drop`, {@see \Compile\MemoryAbi::ARRAY_REPR_CLO}):
        // the static type cannot say whether this buffer counted its closure
        // words, and a `callable` slot may hold a word that is no env at all.
        if ($this->isClosureValueType($el)) { return 'clogated'; }
        // Which element KINDS may drop — `obj,arr` by default, because a
        // compiler built with STRING-element drops miscompiles itself
        // ({@see \Compile\Debug::$elemDropKinds} carries the repro). Also the
        // bisect hook that attributed the cluster to one flavor in three
        // builds instead of three branches.
        $only = \Compile\Debug::$elemDropKinds;
        $objUnion = $this->own->objUnionElem($el);
        if ($only !== '') {
            $tag = $k === Type::KIND_OBJ || $objUnion ? 'obj'
                : ($k === Type::KIND_STRING ? 'str'
                : ($k === Type::KIND_ARRAY ? 'arr' : 'other'));
            if (!\str_contains($only, $tag)) { return ''; }
        }
        if ($objUnion) { return 'obj'; }
        return $this->discardReleaseFlavor($el);
    }

    /**
     * Set the rc-runtime flags for every non-struct class property's release
     * flavor, so the helpers drop_dispatch references (vec / assoc element
     * walkers, str rc) are emitted. Runs before any helper is built (top of
     * emitPreamble). Mirrors {@see rcReleaseReg}'s flag vocabulary.
     */
    private function scanDropFlags(): void
    {
        // Every declared property NAME is a key in that class's generated
        // `@__mir_props_<id>` ({@see EmitLlvmRuntime}), and that body is emitted
        // with the descriptors — LONG after the string pool has rendered. Intern
        // the names HERE, at the top of the preamble, or `litStr` mints an id
        // whose global no longer exists and clang rejects the module with
        // `use of undefined value '@.str.N'`.
        foreach ($this->classes as $cls) {
            foreach ($cls->propertyNames as $pn) { $this->pool->intern($pn); }
        }
        foreach ($this->classes as $cls) {
            if ($cls->isStruct) { continue; }
            foreach ($cls->propertyNames as $pn) {
                $pt = $cls->propertyTypes[$pn] ?? null;
                if ($pt === null) { continue; }
                $flavor = $this->discardReleaseFlavor($pt);
                // Unified arrays: every vec/assoc flavor releases via
                // __mir_array_release* whose deps (needsRc/needsStrRc) are
                // forced unconditionally in emit(); str/obj likewise covered.
                // An array-SHAPED slot with no flavor of its own counts too: a
                // flavor proven from its stores may still give it an element
                // walker ({@see classDropFlavor}).
                if ($flavor !== '' || $this->propIsArrayShaped($cls, $pn, $pt)) {
                    $this->rt->needsRc = true;
                    $this->rt->needsStrRc = true;
                }
            }
        }
    }

    /** Release-helper symbol for a flavor (no side effects; flags are set in
     *  {@see scanDropFlags}). '' for a non-rc flavor. */
    private function dropHelperFor(string $flavor): string
    {
        if ($flavor === 'str') { return '@__mir_rc_release_str'; }
        if ($flavor === 'obj') { return '@__mir_rc_release'; }
        if ($flavor === 'closure') { return '@__mir_closure_release'; }
        if ($flavor === 'vecobj' || $flavor === 'assocobj') { return \Compile\Debug::$rcSymElem ? '@__mir_array_release_ownel_obj' : '@__mir_array_release_obj'; }
        if ($flavor === 'vecstr' || $flavor === 'assocstr') { return \Compile\Debug::$rcSymElem ? '@__mir_array_release_ownel_str' : '@__mir_array_release_str'; }
        if ($flavor === 'veccell' || $flavor === 'assoccell') { return \Compile\Debug::$rcSymElem ? '@__mir_array_release_ownel_cell' : '@__mir_array_release_cell'; }
        if ($this->arrFlavorSuffix($flavor) !== '') {
            $sfx = $this->arrFlavorSuffix($flavor);
            return (\Compile\Debug::$rcSymElem ? '@__mir_array_release_ownel_' : '@__mir_array_release_') . $sfx;
        }
        if ($flavor === 'vecbuf' || $flavor === 'assocbuf') { return '@__mir_array_release_buf'; }
        if ($flavor === 'vec' || $flavor === 'assoc') { return '@__mir_array_release'; }
        // PAIRWISE-SYMMETRIC: this slot took the element refs in its own store's
        // retain, so its drop gives them back on EVERY release, not only at
        // rc → 0 ({@see classDropFlavor}). Same vocabulary as
        // {@see EmitLlvmMemory::rcReleaseReg}, so the two cannot drift.
        if ($flavor === 'vecobjown' || $flavor === 'assocobjown') { return '@__mir_array_release_ownel_obj'; }
        if ($flavor === 'vecstrown' || $flavor === 'assocstrown') { return '@__mir_array_release_ownel_str'; }
        if ($flavor === 'veccellown' || $flavor === 'assoccellown') { return '@__mir_array_release_ownel_cell'; }

        return '';
    }

    /**
     * The release flavor a CLASS DROP body uses for one property slot — the
     * plain {@see discardReleaseFlavor}, or its `own` twin when the slot holds
     * one element ref per element and may give it back on every release.
     *
     * The arithmetic that makes this the fix and not a double free: a buffer's
     * elements carry ONE base ref plus one per retain, against retains+1
     * releases, so every release returning EXACTLY one is balanced. Today the
     * drop returns nothing whenever the buffer outlives the object — the local
     * that built the array is still holding it — and every element is stranded.
     *
     * ⚠ Three ODR conditions, not heuristics. `__mir_drop_<id>` is `linkonce_odr`
     * and coalesces BY NAME across every object file that emits the class, so a
     * module-local verdict may only be used where this module is the only
     * emitter: never for an IMPORTED or PRELUDE class, and never from a LIBRARY
     * module (which cannot see the stores in the programs that link it).
     */
    /**
     * ⚠ Trace EVERY slot, not only the deepened ones. The `CLASSDROP … YES`
     * line below used to print on the one path that upgrades a flavor, so a
     * property whose verdict is the EMPTY flavor — the one that emits no
     * release at all and leaks the array whole — was INVISIBLE, and read as
     * "this slot was never considered" — an int or bool slot legitimately has
     * none, an array slot with none leaks whole, and only the line tells them
     * apart. `Parser\Ast\Program::statements` is
     * exactly that slot: `Block::statements`, the same `Stmt[]` declaration one
     * class over, prints `YES vecobjown`, and the pair only became a question
     * once both verdicts were on screen.
     */
    private function classDropFlavor(\Compile\Mir\ClassDef $cls, string $prop, Type $pt): string
    {
        $f = $this->classDropFlavorFor($cls, $prop, $pt);
        if (\getenv('MANTICORE_DROP_TRACE') !== false) {
            \error_log('CLASSSLOT ' . $cls->name . '::' . $prop
                . ' => ' . ($f === '' ? '(none)' : $f)
                . ' type=' . $pt->toString()
                . ' arrayHinted=' . (($cls->propertyArrayHinted[$prop] ?? false) ? '1' : '0'));
        }
        return $f;
    }

    private function classDropFlavorFor(\Compile\Mir\ClassDef $cls, string $prop, Type $pt): string
    {
        // A closure-typed slot owns the env it holds, as an object slot owns
        // its object: every store takes a count (a fresh literal / call return
        // brings its own, a borrow is retained by rcRetainByType's closure arm).
        if ($this->isClosureValueType($pt)) { return 'closure'; }
        $flavor = $this->discardReleaseFlavor($pt);
        if (!\Compile\Debug::$rcPropDrop) { return $flavor; }
        if ($this->moduleIsLibrary || $cls->isExternClass || $cls->isPreludeClass) { return $flavor; }
        // A BARE `array` slot names no element type, so it gets no element-aware
        // drop: at best the repr-dispatching `__mir_array_release`, which finds
        // repr bits of zero on a CONCRETE buffer ({@see EmitLlvmArrays::
        // erasedReprCode} deliberately never stamps one) and frees the buffer
        // while stranding everything in it — and at worst, once the hint has
        // erased to `unknown`, NO flavor at all, which drops the property from
        // this body and leaks the array whole. Meanwhile the STORE retained at
        // the value's own flavor, so the elements were co-owned and nothing ever
        // gives them back: `public array $kids = []` + `$h->kids = $kids` leaked
        // 226 MB over 100 k calls where the same slot declared `@var Nd[]` is
        // flat ({@see tools/prof/bare_array_prop.php}).
        //
        // The flavor proven from the slot's own stores may REPLACE an erased one;
        // it may never deepen a concrete one it disagrees with.
        $erased = $this->isErasedSlotFlavor($flavor) && $this->propIsArrayShaped($cls, $prop, $pt);
        if (!$erased && !$this->isOwnElemFlavor($flavor)) { return $flavor; }
        $key = $this->cellPropKey($cls->name, $prop);
        $proven = $this->propOwnElem[$key] ?? '';
        if ($proven === '') { return $flavor; }
        if (!$erased && $proven !== $flavor) { return $flavor; }
        if (isset($this->propOwnElemVeto[$key]) || isset($this->propOwnElemVeto[$prop])) { return $flavor; }
        if ($erased) {
            // Two gates the concrete case does not need. An ELEMENT STORE through
            // the slot (`$this->kids[] = $x`) fills the buffer with no whole-slot
            // store to prove anything, and stamps a repr the plain release
            // already honours — deepening on top of that double-drops.
            if (isset($this->cellPropArrayBase[$key]) || isset($this->cellPropArrayBase[$prop])) {
                return $flavor;
            }
            // And `clone`: __mir_array_copy co-owns in REPR mode, so the copy of
            // an unstamped concrete buffer holds its elements as BORROWS.
            if ($this->classMayBeCloned($cls->name)) { return $flavor; }
        }
        if (\getenv('MANTICORE_DROP_TRACE') !== false) {
            \error_log('CLASSDROP ' . $cls->name . '::' . $prop . ' YES ' . $proven . 'own'
                . ($erased ? ' (erased slot, flavor proven from its stores)' : ''));
        }
        return $proven . 'own';
    }

    /** Is this slot an ARRAY at runtime whatever its static type says — declared
     *  `array`, a typed array, or an `array` hint the element erasure flattened
     *  to `unknown`? The release helpers are tag-guarded and NULL-safe, so this
     *  only has to be right about the SHAPE, never about the elements. */
    private function propIsArrayShaped(\Compile\Mir\ClassDef $cls, string $prop, Type $pt): bool
    {
        if ($pt->isArray()) { return true; }
        return $cls->propertyArrayHinted[$prop] ?? false;
    }

    /**
     * Flavor for freeing a fresh owned obj/vec/assoc temp passed as a
     * borrow argument, or '' when the arg is not a guaranteed-owned (+1)
     * producer. Owned producers: `new`, array literal, method / static
     * call, and user free-function call (a builtin may return a borrowed
     * element — `current()` etc. — so it is excluded, as is a closure
     * invoke). Mirrors {@see isFreshStringTemp} for the string flavor.
     */
    /**
     * The release flavor for a fresh rc ARG TEMP, deepened to element level when
     * the callee is proven to CO-OWN rather than consume.
     *
     * The temp holds the producer's BASE reference, which owns one ref per
     * element. Releasing it with the plain flavor gives that back only at
     * rc → 0 — and a callee that stored the value into a property has already
     * taken rc to 2, so the plain release returns nothing and every element is
     * stranded. A by-VALUE parameter of a known callee enters BORROWED and takes
     * its own element-depth reference for whatever it keeps (a store or a
     * return retains at the destination's depth), which is exactly that proof.
     *
     * Unproven cases keep the plain flavor: an unknown signature, a by-REF
     * parameter (the callee "co-owns nothing"), or a position past the
     * declaration. One owner for this question, so the ctor / call / method
     * paths cannot drift apart.
     *
     * @param Type[]   $ptypes callee parameter types, `$this` at 0 where present
     * @param bool[]   $mask   by-ref flags, same indexing
     */
    private function coOwnedArgFlavor(string $flavor, array $ptypes, array $mask, int $pos): string
    {
        if (!$this->isOwnElemFlavor($flavor)) { return $flavor; }
        if (!isset($ptypes[$pos]) || ($mask[$pos] ?? false)) { return $flavor; }
        // ⚠ BOTH ends must carry elements. The entry retain uses the PARAMETER's
        // own flavor, so a parameter declared as a bare `array` retains in repr
        // mode and co-owns NOTHING at element depth — the callee holds the
        // elements only as borrows. Giving one back here then frees a live
        // object under it: `new Cell('yo', ['style' => new Style('bold')])`
        // printed an empty string for `$d->style()->n`, caught by
        // `hetero_prop_default_vs_getter`. A proof checked at one end is not a
        // proof.
        if (!$this->isOwnElemFlavor($this->discardReleaseFlavor($ptypes[$pos]))) {
            return $flavor;
        }
        return $flavor . 'own';
    }

    private function builtinMintsOwnedArray(string $fn): bool { return \Compile\Mir\Ownership::builtinMintsOwnedArray($fn); }

    /** {@see \Compile\Mir\Ownership::tempArgFlavor} */
    private function freshRcArgFlavor(Node $a): string { return $this->own->tempArgFlavor($a, $this->lastCallWasBuiltin); }

    /**
     * Release flavor of an owned temp array a MERGE consumed — a spread source,
     * a union operand — or '' for a borrow. The merge co-owned every element it
     * copied, so the temp goes whole. Not {@see freshRcArgFlavor}'s buffer-only
     * answer for a literal of arrays: that one holds only while each inner
     * array is a call argument released on its own, and a merge operand's
     * elements were transferred into the literal.
     */
    private function mergedTempFlavor(Node $a): string
    {
        if ($a->kind === Node::KIND_ARRAY_LIT) { return $this->discardReleaseFlavor($a->type); }
        return $this->freshRcArgFlavor($a);
    }

    /**
     * Release flavor for the SOURCE of a cellify rebuild
     * ({@see EmitLlvmBuiltins::emitAssocToCellArrayUnified}), or '' to leave it
     * alone.
     *
     * The rebuild allocates a FRESH cell array and co-owns every element it
     * copies, so the source array is dead the instant the walk ends. Whether we
     * may free it is purely "was it an owned temp?" — the same question
     * {@see freshRcArgFlavor} answers for a fresh temp handed to a borrowing
     * callee, so it is answered THERE and not copied here. A `LoadLocal` source
     * is never a temp: either {@see InsertMemoryOps::isOwnedObj} registered it
     * and its own scope-exit release covers it, or it is a borrow — freeing it
     * here would double-free in the first case and over-release in the second.
     * The return path is the one place a returned owned LOCAL is also dead at
     * the rebuild; {@see EmitLlvmModule::emitReturn} handles that by dropping
     * the transfer exemption, not by widening this predicate.
     */
    private function cellifySourceFlavor(Node $src): string
    {
        if ($src->kind === Node::KIND_LOAD_LOCAL) { return ''; }
        return $this->freshRcArgFlavor($src);
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
        // An ALREADY-BOXED cell moved into a cell slot (`$out[$k] = $v` where
        // `$v` is a foreach value off another cell array) is stored by its
        // tagged payload — the destination's release runs __mir_cell_drop on
        // it, so a borrowed source needs the mirror retain or the payload is
        // freed while the destination still points at it. rcRetainByType bails
        // on KIND_CELL (it is a raw i64 there, never inttoptr'd), so route it
        // through the tag-dispatched helper directly; it no-ops on an
        // int/float/bool/null cell. An OWNED producer's +1 transfers.
        // KIND_UNKNOWN travels the same way and must retain for the same reason:
        // the destination is a cell container, so its release runs
        // `__mir_cell_drop` on this slot regardless of what the STATIC type
        // called it. The identical body typed `$v` a cell as a module function
        // and `unknown` as a monomorphised PRELUDE clone, so `array_merge`
        // silently skipped the retain that `mymerge` emitted — every element of a
        // merged array was freed while the result still pointed at it
        // (`is_array($r[0])` true, `count($r[0])` 0, every symfony Table cell
        // blank).
        if ($k === Type::KIND_CELL || $k === Type::KIND_UNKNOWN) {
            // `__mir_to_cell($x)` is pure BOXING ({@see EmitLlvmBuiltins::biToCell}
            // = emit the arg, then boxToCell), so ownership follows its ARGUMENT.
            // Reading the call itself as an owned producer stored the payload with
            // NO co-owner: `__preg_cells` boxed borrowed `$groups` elements into a
            // vec[cell], the caller's per-iteration release of $groups freed them,
            // and preg_replace_callback's closure read `$mm[0]` out of a reused
            // block ('<' for '3'). It only ever worked because the append site
            // double-retained the string before the ownership contract landed.
            $src = $this->cellBoxSource($value);
            $vk = $src->kind;
            if ($this->condOwnsResult($src)) { return ''; }
            if ($vk === Node::KIND_CALL || $vk === Node::KIND_METHOD_CALL
                || $vk === Node::KIND_STATIC_CALL || $vk === Node::KIND_INVOKE
                || $vk === Node::KIND_ARRAY_LIT || $vk === Node::KIND_NEW_OBJ
                || $vk === Node::KIND_CLONE || $vk === Node::KIND_CONCAT
                || \Compile\Mir\BitOp::mintsFresh($src)) {
                return '';
            }
            $sv = $this->lastValue;
            $st = $this->lastValueType;
            $o = $this->coerceToI64();
            // An ERASED word may still be RAW (a bare-`array` local, a conditional
            // over one), and the tag retain is a no-op on an untagged word — the
            // container then held it with no count and freed it under the local.
            // Retain through a probe-boxed copy: raw buffer, raw object or cell alike.
            if ($k === Type::KIND_UNKNOWN) { $o .= $this->boxUnknownShallowIr(); }
            $o .= $this->rcRetainReg($this->lastValue, 'cell');
            $this->lastValue = $sv;
            $this->lastValueType = $st;
            return $o;
        }
        // A closure boxes as an OBJECT cell whose drop reaches the env
        // (`__mir_cell_drop` → `__mir_closure_release`), so the box co-owns it
        // like any object: rcRetainByType's closure arm, fresh ones transfer.
        // A borrowed one (a `\Closure` param appended to a cell element) stored
        // with no co-owner was freed by the caller's release of its temporary
        // while the array still held it.
        if ($k !== Type::KIND_STRING && $k !== Type::KIND_OBJ && $k !== Type::KIND_UNION
            && $k !== Type::KIND_CLOSURE && !$borrowedCellArray) {
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

    /**
     * Look through a pure boxing call to the value whose ownership actually
     * decides a cell co-owner retain. `__mir_to_cell($x)` emits `$x` and boxes
     * it — the call node is not a producer, `$x` is.
     */
    private function cellBoxSource(Node $value): Node
    {
        if ($value->kind !== Node::KIND_CALL) { return $value; }
        if (\ltrim($value->function, '\\') !== '__mir_to_cell') { return $value; }
        $args = $value->args;
        if (\count($args) !== 1) { return $value; }
        return $this->cellBoxSource($args[0]);
    }

    private function isEmptyArrayLit(Node $n): bool
    {
        return $n->kind === Node::KIND_ARRAY_LIT
            && \count($n->elements) === 0;
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
        $ts = $this->resolveMethodClass($cls, '__toString');
        if ($ts !== '' || isset($this->classes[$cls])) { return $ts; }
        // An INTERFACE static type (`Stringable $s`, `Throwable $e`) has no
        // ClassDef of its own: any implementer that answers __toString will do
        // as the direct-call fallback, and toStringCandidates dispatches.
        foreach ($this->interfaceImplementers($cls) as $impl) {
            $t = $this->resolveMethodClass($impl, '__toString');
            if ($t !== '') { return $t; }
        }
        return '';
    }

    /**
     * Every class that implements interface `$iface`, directly or through an
     * ancestor or a parent interface.
     *
     * @return string[]
     */
    private function interfaceImplementers(string $iface): array
    {
        $out = [];
        foreach ($this->classes as $cd) {
            $nm = $cd->name;
            if ($this->classIsA($nm, $iface)) { $out[] = $nm; }
        }
        return $out;
    }

    /** The STATIC class of an expression, for the `__toString` dispatch. */
    private function staticClassOf(Node $e): string
    {
        if ($e->type->kind !== Type::KIND_OBJ) { return ''; }
        return $e->type->class ?? '';
    }

    /**
     * Classes a `__toString` call site can really reach: the static class and
     * every descendant that resolves the method to a DIFFERENT body. One entry
     * means the direct call is exact, which is the common case and keeps the
     * old IR byte-for-byte.
     *
     * @return string[]
     */
    private function toStringCandidates(string $staticClass, string $tsClass): array
    {
        if ($staticClass === '') { return [$tsClass]; }
        $seen = [];
        $out = [];
        $reach = isset($this->classes[$staticClass])
            ? $this->selfAndDescendants($staticClass) : $this->interfaceImplementers($staticClass);
        // EVERY reaching class, not one per distinct body: the dispatch matches
        // an exact class id (arms sharing a body merge there), so a class left
        // out fell to the default — `Y extends X` inherited X's __toString and
        // printed the static base's.
        foreach ($reach as $d) {
            $t = $this->resolveMethodClass($d, '__toString');
            if ($t === '') { continue; }
            $seen[$t] = true;
            $out[] = $d;
        }
        if ($out === [] || \count($seen) === 1) { return [$tsClass]; }
        return $out;
    }

    /**
     * Given `$this->lastValue` holding an object, call its (already
     * resolved) `$tsClass::__toString` and leave the resulting string
     * ptr in `$this->lastValue`. Returns the IR.
     */
    private function emitToStringCall(string $tsClass, string $staticClass = ''): string
    {
        $out = $this->coerceToI64();
        $obj = $this->lastValue;
        // VIRTUAL, when the static type has descendants that answer differently.
        // `__toString` was resolved once, from the STATIC class, and called
        // directly — so a value typed as a base printed the BASE's answer
        // whatever it really was. `(string)$type` over php's own
        // ReflectionType hierarchy is the witness: every subclass returned the
        // base's empty string. Reuses the ordinary method dispatch, so the two
        // cannot drift.
        $cands = $this->toStringCandidates($staticClass, $tsClass);
        if (\count($cands) > 1) {
            $targets = [];
            foreach ($cands as $c) {
                $targets[$c] = $this->resolveMethodClass($c, '__toString') . '____toString';
            }
            $out .= $this->emitVirtualDispatch($obj, 'i64 ' . $obj, $cands, $targets,
                $tsClass . '____toString', '__toString');
            $p0 = $this->ssa->allocReg();
            $out .= '  ' . $p0 . ' = inttoptr i64 ' . $this->vdResult . " to ptr\n";
            $this->lastValue = $p0;
            $this->lastValueType = 'ptr';
            return $out;
        }
        $r = $this->ssa->allocReg();
        $out .= '  ' . $r . ' = call i64 @manticore_' . $this->mangle($tsClass) . '____toString(i64 ' . $obj . ")\n";
        $p = $this->ssa->allocReg();
        $out .= '  ' . $p . ' = inttoptr i64 ' . $r . " to ptr\n";
        $this->lastValue = $p;
        $this->lastValueType = 'ptr';
        return $out;
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
    /** @var array<string, bool> */
    private array $anyHasMethodMemo = [];

    /** Does any class of the module (own or inherited) define `$method`? */
    private function anyClassHasMethod(string $method): bool
    {
        if (isset($this->anyHasMethodMemo[$method])) { return $this->anyHasMethodMemo[$method]; }
        $has = false;
        foreach ($this->classes as $cname => $unused) {
            if ($this->resolveMethodClass($cname, $method) !== '') { $has = true; break; }
        }
        $this->anyHasMethodMemo[$method] = $has;
        return $has;
    }

    private function isClosureClass(string $cls): bool { return \Compile\Mir\Ownership::isClosureClass($cls); }

    private function isClosureValueType(Type $t): bool { return \Compile\Mir\Ownership::isClosureValueType($t); }

    /** An enum case is a value-type ORDINAL (no rc header) — never rc-managed,
     *  like an int. `$cls` is an obj type's class name. */
    private function isEnumClass(string $cls): bool { return $this->own->isEnumClass($cls); }

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
    /** A by-ref param fed a non-lvalue (an omitted default) still takes an
     *  address: each call site's next arm backs it with a throwaway slot
     *  ({@see EmitLlvmCalls::emitRefValueSlot}) and drops what the callee
     *  wrote there. Routing it down the by-VALUE path handed the callee the
     *  value as its address. */
    private function argIsByRef(array $mask, int $pi, Node $a): bool
    {
        return ($mask[$pi] ?? false) && $this->isByRefAddressable($a);
    }

    /** Push a trace frame (`display` name + call-site `line`) before a user call;
     *  no-op unless the program queries traces. */
    private function btPush(string $display, int $line): string
    {
        if (!$this->rt->needsBacktrace) { return ''; }
        $fn = $this->frame->name;
        if ($fn === '') {
            return '  call void @__mir_bt_push(ptr ' . $this->strLitId($this->pool->intern($display))
                 . ', i64 ' . (string)$line . ")\n";
        }
        // Relative to the function's first traced line, which lives in ONE
        // global per function: a line inserted above a function moves that
        // global and nothing in the function's body. With the absolute line in
        // every call, one edit rewrote every later function of the file — every
        // split part, so the object cache never hit.
        if (!isset($this->btBaseLine[$fn])) {
            $this->btBaseLine[$fn] = $line;
            $this->litTableBodies .= '@.btl.' . $this->mangle($fn) . ' = linkonce_odr constant i64 '
                . (string)$line . "\n";
        }
        return '  call void @__mir_bt_push_rel(ptr ' . $this->strLitId($this->pool->intern($display))
             . ', ptr @.btl.' . $this->mangle($fn) . ', i64 ' . (string)($line - $this->btBaseLine[$fn]) . ")\n";
    }

    /** Pop the frame pushed by {@see btPush} after the call returns. */
    private function btPop(): string
    {
        return $this->rt->needsBacktrace ? "  call void @__mir_bt_pop()\n" : '';
    }

    /**
     * Push this call site's as-written argument count onto the func-args side
     * channel, for a callee whose body asks for it. Emitted IMMEDIATELY before
     * the call — the callee takes it in its first statement, so nothing may run
     * in between.
     *
     * Silent (and free) for every other callee: `$srcArgc` is -1 when the
     * lowering path did not record one, and a callee that never asks leaves the
     * channel alone.
     */
    private function faPush(string $callee, int $srcArgc, array $args = [], int $recvParams = 0): string
    {
        if ($srcArgc < 0) { return ''; }
        if (!($this->sigs->usesFuncArgs[$callee] ?? false)) { return ''; }
        $this->rt->needsFuncArgs = true;
        $out = '';
        // Arguments past the callee's real arity have no parameter to land in.
        // Decided HERE and not at lowering: a stdlib entry routinely declares
        // fewer parameters than php accepts and lets its emitter builtin read
        // the rest, so "surplus" is only meaningful once the callee is known to
        // read them back.
        //
        // `$recvParams` is how many leading parameters the callee has that the
        // node's argument list does NOT carry — 1 for an instance method or a
        // constructor, whose params[0] is `this`, and 0 for a free or static
        // call. Without it every instance method looked one argument short of
        // its own arity and its overflow was never built.
        $arity = \count($this->sigs->paramTypes[$callee] ?? []) - $recvParams;
        if ($arity < 0) { $arity = 0; }
        $over = [];
        $ai = 0;
        foreach ($args as $a) {
            if ($ai >= $arity) { $over[] = new \Compile\Mir\ArrayElement_(null, $a); }
            $ai = $ai + 1;
        }
        if (\count($over) > 0) {
            // Built first: it is ordinary array construction and may itself
            // call, which would clobber the count word.
            $out .= $this->emitNode(new \Compile\Mir\ArrayLit($over, Type::vec(Type::cell())));
            $out .= $this->coerceToI64();
            $out .= '  store i64 ' . $this->lastValue . ", ptr @__mir_fa_argx\n";
        }
        return $out . '  store i64 ' . (string)$srcArgc . ", ptr @__mir_fa_argc\n";
    }

    /** The prefix of `$args` the callee actually has parameters for. Keeps the
     *  emitted call matching its `declare` — an UNCONDITIONAL invariant, not a
     *  func-args one: php accepts surplus positional arguments on any call, and
     *  passing them anyway emits `call @f(i64, i64)` against `declare @f(i64)`,
     *  which LLVM treats as undefined behaviour. `json_encode($assoc, $flags)`
     *  SIGSEGV'd that way — the poisoned return reached `__mir_rc_release_str`
     *  as a tagged cell. A variadic callee is already packed to exactly
     *  `count(paramTypes)` arguments BEFORE the call, so it needs no exception;
     *  a func-args callee additionally re-evaluates the surplus into the
     *  overflow array ({@see faPush}), and everything else evaluates it for
     *  effect ({@see Passes\EmitLlvmCalls::surplusArgEffects}).
     *  @param Node[] $args @return Node[] */
    private function faCallArgs(string $callee, array $args, int $recvParams = 0): array
    {
        // Arity unknown — an undefined-function trap or an `rt_` FFI primitive
        // whose `declare` is synthesised FROM the call site. Nothing to match.
        if (!isset($this->sigs->paramTypes[$callee])) { return $args; }
        $arity = \count($this->sigs->paramTypes[$callee]) - $recvParams;
        if ($arity < 0) { $arity = 0; }
        if (\count($args) <= $arity) { return $args; }
        // `f(...$arr)` expands ONE node across the callee's remaining params, so
        // a positional count proves nothing here — the spread arm fills them.
        foreach ($args as $a) {
            if ($a->kind === Node::KIND_SPREAD) { return $args; }
        }
        $kept = [];
        $ai = 0;
        foreach ($args as $a) {
            if ($ai >= $arity) { break; }
            $kept[] = $a;
            $ai = $ai + 1;
        }
        return $kept;
    }

    /** The tail {@see faCallArgs} dropped — the arguments php evaluates and the
     *  callee has no parameter for. @param Node[] $args @return Node[] */
    private function faSurplusArgs(string $callee, array $args, int $recvParams = 0): array
    {
        $kept = \count($this->faCallArgs($callee, $args, $recvParams));
        if ($kept >= \count($args)) { return []; }
        $over = [];
        $ai = 0;
        foreach ($args as $a) {
            if ($ai >= $kept) { $over[] = $a; }
            $ai = $ai + 1;
        }
        return $over;
    }

    /** {@see faCallArgs} for a callee whose params[0] is the receiver.
     *  @param Node[] $args @return Node[] */
    private function faCallArgsRecv(string $callee, array $args): array
    {
        return $this->faCallArgs($callee, $args, 1);
    }

    /**
     * Clear the channel after the call returns, pairing {@see faPush} the way
     * {@see btPop} pairs {@see btPush}.
     *
     * The callee's prologue normally empties it on the way in, so this is a
     * no-op — except when the push reached a callee that does NOT read it
     * (an indirect closure invoke, where the target is not known at the call
     * site). Without the pop that count would still be sitting there when some
     * later frame took it, and a stale count is far worse than no count: the
     * empty channel has a defined meaning (fall back to the declared arity)
     * and a stale one does not.
     */
    private function faPop(): string
    {
        if (!$this->rt->needsFuncArgs) { return ''; }
        return "  store i64 -1, ptr @__mir_fa_argc\n"
             . "  store i64 0, ptr @__mir_fa_argx\n";
    }

    /**
     * {@see faPush} for a virtual dispatch: the receiver's class is not known
     * here, so the channel is armed if ANY candidate reads it. Arming it for a
     * callee that does not is harmless — the value is only ever read by a
     * prologue that asked for it, and {@see faPop} clears what is left.
     *
     * @param string[] $callees
     */
    private function faPushAny(array $callees, int $srcArgc, array $args = [], int $recvParams = 0): string
    {
        foreach ($callees as $cal) {
            if ($this->sigs->usesFuncArgs[$cal] ?? false) {
                return $this->faPush($cal, $srcArgc, $args, $recvParams);
            }
        }
        return '';
    }

    /**
     * Build a packed vec of the active call frames from `$global`
     * (@__mir_bt_name or @__mir_bt_line), innermost first (index depth-1 → 0);
     * lastValue ← the vec ptr as i64. Shared by the backtrace builtin and the
     * Throwable trace capture.
     */
    /**
     * The active frames of `$global` (`@__mir_bt_name` / `@__mir_bt_line`) as a
     * fresh packed vec, innermost first — ONE body per global, called: the copy
     * loop used to be inlined twice at every Throwable construction.
     */
    private function emitBtVec(string $global): string
    {
        $key = '__mc_btvec_' . \ltrim($global, '@');
        $sym = '@manticore_' . $key;
        if (!isset($this->propertyReadHelpers[$key])) {
            $oldSsa = $this->ssa;
            $oldLast = $this->lastValue;
            $oldLastType = $this->lastValueType;
            $this->ssa = new \Compile\Mir\SsaBuilder();
            $this->ssa->reset();
            $body = $this->btVecLoopIr($global);
            $this->propertyReadHelpers[$key] = 'define linkonce_odr i64 ' . $sym . "() {\nentry:\n"
                . $body . '  ret i64 ' . $this->lastValue . "\n}\n\n";
            $this->ssa = $oldSsa;
            $this->lastValue = $oldLast;
            $this->lastValueType = $oldLastType;
        }
        $r = $this->ssa->allocReg();
        $this->lastValue = $r;
        $this->lastValueType = 'i64';
        return '  ' . $r . ' = call i64 ' . $sym . "()\n";
    }

    private function btVecLoopIr(string $global): string
    {
        $dep = $this->ssa->allocReg();
        $out = '  ' . $dep . " = load i64, ptr @__mir_bt_depth\n";
        $slot = $this->ssa->allocReg();
        $out .= '  ' . $slot . " = alloca ptr\n";
        $nv = $this->ssa->allocReg();
        $out .= '  ' . $nv . ' = call ptr @__mir_array_alloc(i64 ' . $dep . ")\n";
        $out .= '  store ptr ' . $nv . ', ptr ' . $slot . "\n";
        $iSlot = $this->ssa->allocReg();
        $out .= '  ' . $iSlot . " = alloca i64\n";
        $i0 = $this->ssa->allocReg();
        $out .= '  ' . $i0 . ' = sub i64 ' . $dep . ", 1\n";
        $out .= '  store i64 ' . $i0 . ', ptr ' . $iSlot . "\n";
        $cond = $this->ssa->allocLabel('bt.cond');
        $body = $this->ssa->allocLabel('bt.body');
        $end  = $this->ssa->allocLabel('bt.end');
        $out .= '  br label %' . $cond . "\n" . $cond . ":\n";
        $i = $this->ssa->allocReg();
        $out .= '  ' . $i . ' = load i64, ptr ' . $iSlot . "\n";
        $c = $this->ssa->allocReg();
        $out .= '  ' . $c . ' = icmp sge i64 ' . $i . ", 0\n";
        $out .= '  br i1 ' . $c . ', label %' . $body . ', label %' . $end . "\n";
        $out .= $body . ":\n";
        $ep = $this->ssa->allocReg();
        $out .= '  ' . $ep . ' = getelementptr inbounds [4096 x i64], ptr ' . $global . ', i64 0, i64 ' . $i . "\n";
        $ev = $this->ssa->allocReg();
        $out .= '  ' . $ev . ' = load i64, ptr ' . $ep . "\n";
        $cur = $this->ssa->allocReg();
        $out .= '  ' . $cur . ' = load ptr, ptr ' . $slot . "\n";
        $nx = $this->ssa->allocReg();
        $out .= '  ' . $nx . ' = call ptr @__mir_array_append(ptr ' . $cur . ', i64 ' . $ev . ")\n";
        $out .= '  store ptr ' . $nx . ', ptr ' . $slot . "\n";
        $i2 = $this->ssa->allocReg();
        $out .= '  ' . $i2 . ' = sub i64 ' . $i . ", 1\n";
        $out .= '  store i64 ' . $i2 . ', ptr ' . $iSlot . "\n";
        $out .= '  br label %' . $cond . "\n" . $end . ":\n";
        $dst = $this->ssa->allocReg();
        $out .= '  ' . $dst . ' = load ptr, ptr ' . $slot . "\n";
        $r = $this->ssa->allocReg();
        $out .= '  ' . $r . ' = ptrtoint ptr ' . $dst . " to i64\n";
        $this->lastValue = $r;
        $this->lastValueType = 'i64';
        return $out;
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
            // The GLOBAL-CELL arm of {@see EmitLlvmLocals::byRefAddrOf}: the
            // module cell is the storage. Without it `fill($_GET)` with
            // `array &$out` was "not an lvalue", rode the by-VALUE path, and
            // the callee dereferenced the array pointer as a slot address —
            // and so did `static $v = []; fill($v)` and `global $g; fill($g)`,
            // which reached the same by-VALUE path and SIGSEGV'd.
            return isset($this->locals->slots[$a->name])
                || $this->byRefGlobalCellOf($a->name) !== '';
        }
        if ($a->kind === Node::KIND_PROPERTY_ACCESS) {
            $pa = $a;
            $cls = $pa->object->type->class ?? '';
            return $cls !== '' && isset($this->classes[$cls]);
        }
        if ($a->kind === Node::KIND_ARRAY_ACCESS) {
            return $this->arrayElemAddressable($a);
        }
        // A static property is an external-linkage global, and
        // {@see EmitLlvmLocals::byRefAddrOf} hands its address over. Not listed
        // here, `uksort(self::$defs, …)` rode the throwaway-slot path: the sort
        // landed in a temporary and the property kept its old order.
        if ($a->kind === Node::KIND_STATIC_PROP) {
            return true;
        }
        return false;
    }

    /** Pure predicate: `$base` has a stable i64 cell holding its array pointer
     *  ({@see containerCellPtr} without emitting). */
    private function containerAddressable(Node $base): bool
    {
        if ($base->kind === Node::KIND_LOAD_LOCAL) {
            $name = $base->name;
            return isset($this->locals->globalBacked[$name]) || isset($this->locals->slots[$name]);
        }
        if ($base->kind === Node::KIND_PROPERTY_ACCESS) {
            $cls = $base->object->type->class ?? '';
            return $cls !== '' && isset($this->classes[$cls]);
        }
        // A NESTED container (`$a['k']` of `&$a['k'][$j]`): its element slot
        // is itself addressable, and {@see containerCellPtr} opens it.
        if ($base->kind === Node::KIND_ARRAY_ACCESS) {
            return $this->arrayElemKeyKind($base->index) !== null
                && $this->containerAddressable($base->array);
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
    /** IR a caller of {@see containerCellPtr} appends after its ref-slot call
     *  (a nested container's write-back); '' otherwise. Taken and cleared. */
    private string $containerCloseIr = '';

    private function containerCellPtr(Node $base): ?string
    {
        if ($base->kind === Node::KIND_LOAD_LOCAL) {
            $name = $base->name;
            if (isset($this->locals->globalBacked[$name])) {
                $this->lastValue = $this->locals->globalBacked[$name];
                $this->lastValueType = 'ptr';
                return '';
            }
            if (!isset($this->locals->slots[$name])) { return null; }
            if (isset($this->locals->refLocals[$name])) {
                // The slot holds the address of the caller's cell — deref once.
                $ai = $this->ssa->allocReg();
                $out = '  ' . $ai . ' = load i64, ptr ' . $this->locals->slots[$name] . "\n";
                $p = $this->ssa->allocReg();
                $out .= '  ' . $p . ' = inttoptr i64 ' . $ai . " to ptr\n";
                $this->lastValue = $p;
                $this->lastValueType = 'ptr';
                return $out;
            }
            $this->lastValue = $this->locals->slots[$name];
            $this->lastValueType = 'ptr';
            return '';
        }
        if ($base->kind === Node::KIND_ARRAY_ACCESS) {
            // A nested container: the ELEMENT slot of the outer array holds the
            // inner one — as a tagged array cell on a cell channel, a raw
            // pointer on a raw one, or nothing yet. The ref-slot helpers work
            // on a raw pointer cell, so the inner array is OPENED into a scratch
            // word (unboxed, or vivified to a fresh empty array — php creates
            // it) and {@see $containerCloseIr} writes it back, re-boxed on a
            // cell channel, once the caller's helper has COW-separated or grown
            // it. Without this `$r = &$a['k'][$j]` degraded to a value copy and
            // every write through it was lost.
            $out = $this->byRefAddrOf($base);
            if ($out === null) { return null; }
            $this->rt->needsTagged = true;
            $ep = $this->ssa->allocReg();
            $out .= '  ' . $ep . ' = inttoptr i64 ' . $this->lastValue . " to ptr\n";
            $w = $this->ssa->allocReg();
            $out .= '  ' . $w . ' = load i64, ptr ' . $ep . "\n";
            $tg = $this->ssa->allocReg();
            $out .= '  ' . $tg . ' = icmp ugt i64 ' . $w . ', ' . '-4503599627370496' . "\n";
            $sh = $this->ssa->allocReg();
            $out .= '  ' . $sh . ' = lshr i64 ' . $w . ", 48\n";
            $nb = $this->ssa->allocReg();
            $out .= '  ' . $nb . ' = and i64 ' . $sh . ", 15\n";
            $isA = $this->ssa->allocReg();
            $out .= '  ' . $isA . ' = icmp eq i64 ' . $nb . ", 7\n";
            $pm = $this->ssa->allocReg();
            $out .= '  ' . $pm . ' = and i64 ' . $w . ", 281474976710655\n";
            $tp = $this->ssa->allocReg();
            $out .= '  ' . $tp . ' = select i1 ' . $isA . ', i64 ' . $pm . ", i64 0\n";
            $p = $this->ssa->allocReg();
            $out .= '  ' . $p . ' = select i1 ' . $tg . ', i64 ' . $tp . ', i64 ' . $w . "\n";
            $scr = $this->ssa->allocReg();
            $out .= '  ' . $scr . " = alloca i64\n";
            $out .= '  store i64 ' . $p . ', ptr ' . $scr . "\n";
            $z = $this->ssa->allocReg();
            $out .= '  ' . $z . ' = icmp eq i64 ' . $p . ", 0\n";
            $mkL = $this->ssa->allocLabel('rc.mk');
            $okL = $this->ssa->allocLabel('rc.ok');
            $out .= '  br i1 ' . $z . ', label %' . $mkL . ', label %' . $okL . "\n";
            $out .= $mkL . ":\n";
            $na = $this->ssa->allocReg();
            $out .= '  ' . $na . " = call ptr @__mir_array_alloc(i64 0)\n";
            $out .= '  store ptr ' . $na . ', ptr ' . $scr . "\n";
            $out .= '  br label %' . $okL . "\n";
            $out .= $okL . ":\n";
            // Written back in the slot's OWN representation: tagged if it was
            // tagged, raw if it was raw. A slot that held nothing takes the
            // outer array's static channel: raw only on a statically ARRAY
            // element, tagged (self-describing) otherwise — an unstamped raw
            // pointer in an erased buffer reads back as a double.
            // (Choosing by the node's type re-boxed a raw inner array in an
            // `unknown`-element property, and its release walked a tagged word.)
            $oel = $base->array->type->element ?? null;
            $staticRaw = $oel !== null && $oel->isArray();
            $bf = $tg;
            if (!$staticRaw) {
                $bf = $this->ssa->allocReg();
                $out .= '  ' . $bf . ' = or i1 ' . $tg . ', ' . $z . "\n";
            }
            $cp = $this->ssa->allocReg();
            $close = '  ' . $cp . ' = load i64, ptr ' . $scr . "\n";
            $cb = $this->ssa->allocReg();
            $close .= '  ' . $cb . ' = or i64 ' . $cp . ", -2533274790395904\n";
            $cf = $this->ssa->allocReg();
            $close .= '  ' . $cf . ' = select i1 ' . $bf . ', i64 ' . $cb . ', i64 ' . $cp . "\n";
            $close .= '  store i64 ' . $cf . ', ptr ' . $ep . "\n";
            $this->containerCloseIr = $close;
            $this->lastValue = $scr;
            $this->lastValueType = 'ptr';
            return $out;
        }
        if ($base->kind === Node::KIND_PROPERTY_ACCESS) {
            // The property field IS the cell holding the array pointer.
            $addr = $this->byRefAddrOf($base);
            if ($addr === null) { return null; }
            $p = $this->ssa->allocReg();
            $addr .= '  ' . $p . ' = inttoptr i64 ' . $this->lastValue . " to ptr\n";
            $this->lastValue = $p;
            $this->lastValueType = 'ptr';
            return $addr;
        }
        return null;
    }

    /**
     * Emit the pre-loop arena position save. The saved (cur, used) are
     * loop-invariant SSA values — computed once before the loop, they
     * dominate the loop header, so no alloca is needed (an alloca here
     * would re-run and grow the stack each outer iteration of a nest).
     */
    private function emitArenaSave(): string
    {
        $this->rt->needsArena = true;
        $this->rt->needsArenaReset = true;
        $cr = $this->ssa->allocReg();
        $ur = $this->ssa->allocReg();
        $this->arena->saveCurReg = $cr;
        $this->arena->saveUsedReg = $ur;
        $out  = '  ' . $cr . " = load ptr, ptr @__mir_arena_cur\n";
        $out .= '  ' . $ur . " = call i64 @__mir_arena_used()\n";
        return $out;
    }

    /** The restore to a save position a loop captured after its own save (a
     *  nested loop's save overwrites {@see ArenaContext::$saveCurReg}). */
    private function arenaRestoreIr(string $cur, string $used): string
    {
        return '  call void @__mir_arena_restore(ptr ' . $cur . ', i64 ' . $used . ")\n";
    }

    /** Emit a reset to the saved arena position (read immediately after save). */
    private function emitArenaReset(): string
    {
        return '  call void @__mir_arena_restore(ptr ' . $this->arena->saveCurReg
            . ', i64 ' . $this->arena->saveUsedReg . ")\n";
    }

    /**
     * Arm the innermost open try's landing mark with a resetting loop's save
     * position; the index of that try, or -1. Only the outermost resetting loop
     * of the try region arms it, and only one whose every other way out passes
     * its exit restore ({@see ArenaContext::reclaimsOwnWindow}), which disarms
     * it: an armed mark always names a loop the control is still inside, so
     * all a restore to it frees is that loop's window, which (A)/(B)/(C) of
     * {@see ArenaContext::canResetPerIteration} leave dead outside the loop,
     * and frames the throw unwound. Called right after {@see emitArenaSave}.
     */
    private function arenaArmTryMark(Node $loop): int
    {
        $k = \count($this->arena->tryMarkCur) - 1;
        if ($k < 0 || $this->arena->tryMarkOpen[$k] === 1
            || !\Compile\Mir\ArenaContext::reclaimsOwnWindow($loop)) { return -1; }
        $this->arena->tryMarkOpen[$k] = 1;
        $this->arena->tryMarkArmed[$k] = 1;
        return $k;
    }

    /** The stores that arm mark `$k` with the save {@see emitArenaSave} just took. */
    private function arenaArmTryMarkIr(int $k): string
    {
        if ($k < 0) { return ''; }
        return '  store ptr ' . $this->arena->saveCurReg . ', ptr ' . $this->arena->tryMarkCur[$k] . "\n"
            . '  store i64 ' . $this->arena->saveUsedReg . ', ptr ' . $this->arena->tryMarkUsed[$k] . "\n";
    }

    /** Disarm on the loop's exit edge, after its exit restore. */
    private function arenaDisarmTryMark(int $k): string
    {
        if ($k < 0) { return ''; }
        $this->arena->tryMarkOpen[$k] = 0;
        return '  store i64 -1, ptr ' . $this->arena->tryMarkUsed[$k] . "\n";
    }

    // ── String pool / escaping ─────────────────────────────────

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
            $h = $this->fnvHash64($key->value);
            return ', i64 ' . (string)$h . ', i64 1';
        }
        return ', i64 0, i64 0';
    }

    /**
     * The string hash — MUST match __mir_array_hash_str and __mc_refl_hash
     * exactly: FNV-1a (basis 0xCBF29CE484222325, prime 0x100000001B3) over
     * little-endian 8-byte words, then the tail bytes, then murmur's fmix64.
     * Byte-at-a-time FNV made every fresh multi-KB key (a php-cs-fixer regex,
     * rebuilt per call) cost a multiply per byte. PHP's `*` overflows to float, so the multiply goes through
     * {@see mulmod64} (16-bit limb schoolbook) — exact under BOTH the Zend
     * bootstrap and the native self-build, which native i64 `mul` would also give.
     */
    private function fnvHash64(string $s): int
    {
        $h = -3750763034362895579; // 0xCBF29CE484222325 as signed i64
        $n = \strlen($s);
        $i = 0;
        // Eight bytes a step, little-endian — the runtime loads an unaligned i64.
        while ($i + 8 <= $n) {
            $w = 0;
            for ($k = 7; $k >= 0; $k = $k - 1) { $w = ($w << 8) | \ord($s[$i + $k]); }
            $h = $this->mulmod64($h ^ $w, 1099511628211);
            $i = $i + 8;
        }
        for (; $i < $n; $i = $i + 1) {
            $h = $this->mulmod64($h ^ \ord($s[$i]), 1099511628211);
        }
        // fmix64: a word step leaves the LOW bits — the bucket index — blind to
        // a word's upper bytes, so everything is folded down before use.
        $h = $h ^ (($h >> 33) & 0x7FFFFFFF);
        $h = $this->mulmod64($h, -49064778989728563);
        $h = $h ^ (($h >> 33) & 0x7FFFFFFF);
        $h = $this->mulmod64($h, -4265267296055464877);
        $h = $h ^ (($h >> 33) & 0x7FFFFFFF);
        return $h;
    }

    // ── Arrays (unified PhpArray, docs/16) ─────────────────────

    // ── Unified PhpArray codegen (docs/16) ─────────────────────
    //
    // One path for every array literal/access/store: all ops route
    // through the `__mir_array_*` helpers, which carry the PACKED/HASHED
    // mode at runtime. There is ONE static array kind (KIND_ARRAY); the
    // vec/assoc distinction is just the key type (int vs string), a hint
    // the runtime can override by promoting on the first string key.

    /** Merge a spread source into `$slot` with PHP key semantics: string keys
     *  preserved (later duplicate overwrites), int keys renumbered. */
    private function emitArraySpreadUnified(string $slot, Spread_ $spreadNode): string
    {
        $sp = $spreadNode;
        $out = $this->emitNode($sp->operand);
        // The merge co-owns every element it copies, so an owned temp source
        // (`[...f()]`, `[...$closure()]`) is dead once it ran — nothing else
        // ever held it.
        $flavor = $this->mergedTempFlavor($sp->operand);
        $word = '';
        if ($sp->operand->type->kind === Type::KIND_CELL) {
            // A cell operand carries its tag bits: read raw, the merge walked
            // the tagged word as a buffer header.
            $out .= $this->coerceToI64();
            $word = $this->lastValue;
            $out .= $this->unboxCellToType(Type::vec(Type::unknown()));
        }
        $out .= $this->coerceToPtr();
        $src = $this->lastValue;
        $cur = $this->ssa->allocReg();
        $out .= '  ' . $cur . ' = load ptr, ptr ' . $slot . "\n";
        $nx = $this->ssa->allocReg();
        $out .= '  ' . $nx . ' = call ptr @__mir_array_spread_into(ptr ' . $cur . ', ptr ' . $src . ")\n";
        $out .= '  store ptr ' . $nx . ', ptr ' . $slot . "\n";
        if ($flavor !== '') {
            if ($word === '') {
                $word = $this->ssa->allocReg();
                $out .= '  ' . $word . ' = ptrtoint ptr ' . $src . " to i64\n";
            }
            $out .= $this->rcReleaseReg($word, $flavor);
        }
        return $out;
    }

    // ── SSA / label minting ────────────────────────────────────

    /** Read a node's type kind through a typed param: a match cond comes
     *  from `foreach ($arm->conds as $c)` where `conds` is `?array` — the
     *  loop var is untyped, so an inline `$c->type->kind` resolves the wrong
     *  field offset (self-host) and reads garbage. Routing through `Node $c`
     *  fixes the offset. */
    private function nodeTypeKind(Node $c): string { return $c->type->kind; }

    private function binLeft(Node $n): Node
    {
        $k = $n->kind;
        if ($k === Node::KIND_ADD) { return $n->left; }
        if ($k === Node::KIND_SUB) { return $n->left; }
        if ($k === Node::KIND_MUL) { return $n->left; }
        if ($k === Node::KIND_DIV) { return $n->left; }
        if ($k === Node::KIND_MOD) { return $n->left; }
        if ($k === Node::KIND_CMP) { return $n->left; }
        if ($k === Node::KIND_SPACESHIP) { return $n->left; }
        throw new \RuntimeException('binLeft: unexpected node kind');
    }

    private function binRight(Node $n): Node
    {
        $k = $n->kind;
        if ($k === Node::KIND_ADD) { return $n->right; }
        if ($k === Node::KIND_SUB) { return $n->right; }
        if ($k === Node::KIND_MUL) { return $n->right; }
        if ($k === Node::KIND_DIV) { return $n->right; }
        if ($k === Node::KIND_MOD) { return $n->right; }
        if ($k === Node::KIND_CMP) { return $n->right; }
        if ($k === Node::KIND_SPACESHIP) { return $n->right; }
        throw new \RuntimeException('binRight: unexpected node kind');
    }
}
