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

    public function name(): string { return 'emit-llvm'; }

    /** Interned string-literal pool (fresh each {@see emit}). */
    private ?StringPool $pool = null;

    /** Per-function SSA register + label allocator (fresh each {@see emit}). */
    private ?SsaBuilder $ssa = null;
    private int $switchCounter = 0;

    // Out-slot for {@see cellTagIr}: the SSA reg holding the computed cell tag.
    private string $cellTagReg = '';

    /** break/continue/finally targets of the current function (fresh each {@see emit}). */
    private ?ControlFlow $cf = null;

    /** Identity + ABI of the function being emitted (fresh each {@see emit}). */
    private ?FunctionEmitFrame $frame = null;

    /** Call-site signature registry for the module (fresh each {@see emit}). */
    private ?FunctionSignatures $sigs = null;

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

    /** @var array<string, true> trait names (trait_exists fold) */
    private array $traitNames = [];

    /** Arg-list suffix produced by the most recent {@see emitDefaultArgPad}. */
    private string $lastPadArgs = '';

    // ── generator state (set while emitting a `$resume` function) ──
    /** Per-function generator emit state (fresh each {@see emit}). */
    private ?GeneratorContext $gen = null;

    /** Program source path (exception file() / trace frames). */
    private string $sourceFile = '';

    /** True while emitting a `$r = &fn()` bind (suppress call-result deref). */
    private bool $rawRefCall = false;

    /** Scratch regs threaded out of {@see emitBagPtr}. */
    private string $bagSlotReg = '';
    private string $bagPtrReg = '';

    /** @var array<string, int> closure fn name → capture count */
    private array $closureCaptures = [];
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
    /** Scratch: address reg set by foreachElemAddr / foreachKeyAddr. */
    private string $feAddr = '';
    /** Scratch: result reg set by emitVirtualDispatch. */
    private string $vdResult = '';
    /** @var string[] module global cell names (static props/locals/global) */
    private array $globalNames = [];
    /** @var Node[] parallel default-init nodes for $globalNames */
    private array $globalDefaults = [];
    /** @var bool[] parallel prelude flags for $globalNames — linkonce_odr when
     *  true, because the prelude is compiled into every module and external
     *  linkage would make stdlib.o and user.o define the same cell twice
     *  ({@see \Compile\Mir\Module::$globalIsPrelude}). */
    private array $globalIsPrelude = [];
    /** @var string[] names declared `global $x` — __main shares the cell */
    private array $globalVarNames = [];

    /** Per-module runtime-feature demand set (fresh each {@see emit}). */
    private ?RuntimeFeatures $rt = null;

    /** @var array<string, MethodMeta> free functions a ReflectionFunction reflects.
     *  Declared LAST — a new field mid-class shifts later offsets, a self-host
     *  layout hazard (the ClassDef::$isPreludeClass lesson). */
    private array $reflFnMeta = [];

    public function emit(Module $module): string
    {
        $this->rt = new RuntimeFeatures();
        // Arena arrays force the arena runtime on: the unified-array grow /
        // promote / index paths reference @__mir_arena_* under this flag, so
        // those symbols must be emitted even if no string took the arena path.
        if (\Compile\Debug::$arenaArrays) { $this->rt->needsArena = true; }
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
        $this->ssa = new SsaBuilder();
        $this->gen = new GeneratorContext();
        $this->cf = new ControlFlow();
        $this->frame = new FunctionEmitFrame();
        $this->sigs = new FunctionSignatures();
        $this->arena = new ArenaContext();
        $this->locals = new LocalSlots();
        $this->lib = new RuntimeLibrary();
        $this->classes = $module->classes;
        $this->reflectNames = $module->reflectNames;
        $this->reflectAll = $module->reflectAll;
        $this->enums = $module->enums;
        $this->typeDefs = $module->typeDefs;
        $this->methodDisplay = $module->needsBacktrace ? $module->methodDisplay : [];
        $this->interfaceNames = $module->interfaceNames;
        $this->traitNames = $module->traitNames;
        $this->reflFnMeta = $module->reflFnMeta;
        $this->closureCaptures = $module->closureCaptures;
        $this->closureHasThis = $module->closureHasThis;
        $this->globalNames = $module->globalNames;
        $this->globalDefaults = $module->globalDefaults;
        $this->globalIsPrelude = $module->globalIsPrelude;
        $this->globalVarNames = $module->globalVarNames;
        $this->rt->needsBacktrace = $module->needsBacktrace;
        $this->sourceFile = $module->sourceFile;
        // Per-function by-ref + tagged(cell) param masks for call sites.
        foreach ($module->functions as $fn) {
            $mask = [];
            $tmask = [];
            $camask = [];
            $ptypes = [];
            $pdefs = [];
            foreach ($fn->params as $p) {
                $mask[] = $p->byRef;
                $tmask[] = ($p->type->kind === Type::KIND_CELL);
                $camask[] = $p->cellArg;
                $ptypes[] = $p->type;
                $pdefs[] = $p->default;
            }
            $this->sigs->refParams[$fn->name] = $mask;
            $this->sigs->taggedParams[$fn->name] = $tmask;
            $this->sigs->cellArgParams[$fn->name] = $camask;
            $this->sigs->paramTypes[$fn->name] = $ptypes;
            $this->sigs->paramDefaults[$fn->name] = $pdefs;
            $this->sigs->returnsByRef[$fn->name] = $fn->returnsByRef;
            $this->sigs->returnType[$fn->name] = $fn->returnType;
            $this->definedFns[$this->mangle($fn->name)] = true;
            if ($fn->name === '__main') { $this->moduleHasMain = true; }
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

    /** Backing kind via a typed param (self-host slot offset). */
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
            // Same spelling as the ordinary path — the symbol coalesces by name,
            // so a type that disagreed would be one symbol defined two ways.
            $out .= \Compile\Mir\RuntimeLibrary::descriptorGlobal($ed->classId, 'ptr null');
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
        $out .= "  %ev = call i64 @__mir_array_value_at(ptr %arr, i64 %i)\n";
        $out .= "  %es = call ptr @__manticore_tagged_to_str(i64 %ev)\n";
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
        $out = $this->intToStrImpl('@__mir_int_to_str', '@__mir_str_alloc');
        if ($this->rt->needsArena) {
            $out .= $this->intToStrImpl('@__mir_int_to_str_arena', '@__mir_str_alloc_arena');
        }
        $out .= $this->intFmtRuntime();
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
        $out = '';
        $n = \strlen($name);
        for ($i = 0; $i < $n; $i = $i + 1) {
            $c = \substr($name, $i, 1);
            if ($c === '\\') { $out .= '_'; } else { $out .= $c; }
        }
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

    private function scanCellPropStores(Node $n): void
    {
        if ($n->kind === Node::KIND_STORE_PROPERTY) {
            // Key by the DECLARING class (+ a bare-name global fallback when the
            // receiver is erased), so a same-named property in an unrelated class
            // no longer poisons this slot's box decision. See cellPropBoxed.
            $key = $this->cellPropKey($n->object->type->class ?? '', $n->property);
            $vk = $n->value->type->kind;
            if ($vk === Type::KIND_ARRAY) {
                // A concrete array can box (boxToCell rebuilds it as a cell-array),
                // but it only does so when the slot is already self-describing —
                // see cellPropBoxed. Tracked separately so an array-only prop keeps
                // its current raw behaviour (no regression for typed-array backing).
                $this->cellPropHasArrayStore[$key] = true;
            } elseif (!$this->cellBoxableKind($n->value->type)) {
                $this->cellPropNotBoxable[$key] = true;
            } else {
                $this->cellPropHasInPlaceBox[$key] = true;
            }
        }
        $base = $this->cellPropArrayBaseKey($n);
        if ($base !== null) { $this->cellPropArrayBase[$base] = true; }
        foreach (\Compile\Mir\Walk::children($n) as $c) {
            $this->scanCellPropStores($c);
        }
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

    private int $iterCounter = 0;

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

    private function collectRcObjLocals(Node $n): void
    {
        if ($n->kind === Node::KIND_MEMORY_OP) {
            $mo = $n;
            if ($mo->op === 'rc_release' && $mo->target !== null
                && $mo->target->kind === Node::KIND_LOAD_LOCAL) {
                // A BY-REF param's slot holds the caller's ADDRESS, not the
                // value — the caller owns the lifetime, the callee co-owns
                // nothing. Registering it as an owned rc local emits a
                // scope-exit release that runs `rc_release(load slot)` =
                // release of the ADDRESS, which decrements the word at
                // (addr-8) — the caller's ADJACENT stack slot. Concretely
                // `f(string &$a, int &$p){ $p=N; $a=g(); }` came back with
                // $p == N-1: the string store to `$a` released `&$a`, and
                // `&$a - 8` was `&$p`. initRcObjSlots already skips the
                // paired retain-on-entry for the same reason; excluding the
                // param here kills the release too, keeping them balanced.
                if (isset($this->locals->refLocals[$mo->target->name])) { return; }
                // Store the MemoryOp node, not its flavor string — the
                // self-host backend corrupts a short string round-tripped
                // through an assoc value (a `'str'` read back mis-compares),
                // but a node handle survives. Flavor is re-derived per use.
                $this->frame->rcObjLocals[$mo->target->name] = $mo;
            }
            return;
        }
        foreach (\Compile\Mir\Walk::children($n) as $c) { $this->collectRcObjLocals($c); }
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
        $name = $valueNode->name;
        if (!isset($this->frame->rcObjLocals[$name])) { return; }
        if (isset($this->frame->paramNames[$name])) { return; }
        if ($this->containerStoreRetains($valueNode, $fallback)) { return; }
        $this->frame->transferredLocals[$name] = true;
    }

    /** @param Node[] $args */
    private function shareCallArgs(array $args): void
    {
        foreach ($args as $a) {
            if ($a->kind === Node::KIND_LOAD_LOCAL) {
                $t = $a->type;
                if ($t->isVec() || $t->isAssoc()) {
                    $el = $t->element;
                    if ($el !== null
                        && ($el->kind === Type::KIND_OBJ || $el->kind === Type::KIND_STRING)) {
                        $this->frame->elementSharedLocals[$a->name] = true;
                    }
                }
            }
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

    /** Whether the local `$name` is the base of an in-place element store
     *  (`$name[$k] = …` / append, or a nested `$name[0][] = …`) anywhere in `$n`
     *  — i.e. mutated as an array, independent of its (possibly erased) type. */
    private function localMutatedAsArray(Node $n, string $name): bool
    {
        if ($n->kind === Node::KIND_STORE_ELEMENT) {
            $base = $n->array;
            while ($base->kind === Node::KIND_ARRAY_ACCESS) {
                $base = $base->array;
            }
            if ($base->kind === Node::KIND_LOAD_LOCAL
                && $base->name === $name) {
                return true;
            }
        }
        foreach (\Compile\Mir\Walk::children($n) as $c) {
            if ($this->localMutatedAsArray($c, $name)) { return true; }
        }
        return false;
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
        return $n->accept($this);
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
        $out .= '  ' . $reg . ' = call i64 @__manticore_tagged_' . $op
              . '(i64 ' . $l . ', i64 ' . $r . ")\n";
        $this->lastValue = $reg;
        $this->lastValueType = 'i64';
        return $out;
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
        return $ids;
    }

    private function classIsA(string $name, string $target): bool
    {
        if ($target === 'Stringable') {
            return $this->resolveMethodClass($name, '__toString') !== '';
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
        $this->rt->needsStrRc = true;
        return '  call void @__mir_rc_release_str(ptr ' . $ptr . ")\n";
    }

    /**
     * Emit a MemoryOp from the plan (#5). Arena scope enter/leave map
     * to real runtime calls; rc release/retain stay no-ops until the rc
     * runtime lands.
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
        if ($mo->op === 'rc_release') {
            // Scope-exit drop of an owned RcHeap vec / obj local.
            $t = $mo->target;
            if ($t !== null && $t->kind === Node::KIND_LOAD_LOCAL) {
                $name = $t->name;
                // A BY-REF param's slot holds an ADDRESS — releasing it frees
                // the caller's slot, not a value we own. The counterpart of the
                // suppressed entry retain ({@see initRcObjSlots}).
                if (isset($this->locals->refLocals[$name])) { return ''; }
                // Transferred (escaped into a borrowing container): ownership
                // moved to the container, so skip the scope-exit release.
                if (isset($this->frame->transferredLocals[$name])) { return ''; }
                if (isset($this->locals->slots[$name])) {
                    return $this->rcReleaseSlot($this->locals->slots[$name], $this->rcReleaseFlavor($mo));
                }
            }
            return '';
        }
        return '';
    }

    /**
     * Flavor string for releasing an rc-managed value of type `$t`, or
     * '' when `$t` is not rc-managed (scalar / void / #[Struct] / closure).
     * Mirrors the {@see rcReleaseReg} vocabulary.
     */
    /** A scalar kind with no rc payload — an array of these needs no
     *  per-element drop, so its release/retain can skip the repr bits. */
    private function isNonRcScalarKind(string $k): bool
    {
        return $k === Type::KIND_INT || $k === Type::KIND_FLOAT
            || $k === Type::KIND_BOOL || $k === Type::KIND_NULL;
    }

    private function discardReleaseFlavor(Type $t): string
    {
        $k = $t->kind;
        if ($k === Type::KIND_STRING) { return 'str'; }
        // A CELL is tag-dispatched by __mir_cell_drop (scalars a no-op). Without
        // this it fell through to '' — so `unset($r)` on a `Foo|false` local
        // released NOTHING and its __destruct never ran.
        if ($k === Type::KIND_CELL) { return 'cell'; }
        if ($k === Type::KIND_OBJ) {
            $cls = $t->class ?? '';
            // `Ffi\Ptr` is a raw foreign address with NO rc header: the word at
            // ptr-8 is the allocator's own metadata, not a refcount. Releasing
            // one decrements that metadata in place and, at zero, hands the
            // block to the string pool — silently corrupting the heap until a
            // later free() trips a libmalloc assertion. Mirrors the guard in
            // rcRetainRawByType; without it a DISCARDED `\Runtime\Libc\memset(...)`
            // (any Ptr-returning FFI call used as a statement) corrupts the heap.
            if ($cls === 'Ffi\\Ptr') { return ''; }
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
            // A concrete scalar element (int/float/bool/null) has nothing to
            // drop → buffer-only, skipping the repr-bit read. Only an ERASED
            // element (unknown) reaches the repr path.
            if ($el !== null && $this->isNonRcScalarKind($el->kind)) { return 'vecbuf'; }
            return 'vec';
        }
        if ($t->isAssoc()) {
            $el = $t->element;
            if ($el !== null && $el->kind === Type::KIND_CELL) { return 'assoccell'; }
            if ($el !== null && $el->kind === Type::KIND_OBJ && !$this->isEnumClass($el->class ?? '')) { return 'assocobj'; }
            if ($el !== null && $el->kind === Type::KIND_STRING) { return 'assocstr'; }
            if ($el !== null && $this->isNonRcScalarKind($el->kind)) { return 'assocbuf'; }
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
                if ($flavor !== '') { $this->rt->needsRc = true; $this->rt->needsStrRc = true; }
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
        if ($flavor === 'vecbuf' || $flavor === 'assocbuf') { return '@__mir_array_release_buf'; }
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
            $fn = $a->function;
            $owned = isset($this->sigs->paramTypes[$fn]) && !($this->sigs->returnsByRef[$fn] ?? false);
        }
        if (!$owned) { return ''; }
        return $this->discardReleaseFlavor($a->type);
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

    /** Push a trace frame (`display` name + call-site `line`) before a user call;
     *  no-op unless the program queries traces. */
    private function btPush(string $display, int $line): string
    {
        if (!$this->rt->needsBacktrace) { return ''; }
        return '  call void @__mir_bt_push(ptr ' . $this->strLitId($this->pool->intern($display))
             . ', i64 ' . (string)$line . ")\n";
    }

    /** Pop the frame pushed by {@see btPush} after the call returns. */
    private function btPop(): string
    {
        return $this->rt->needsBacktrace ? "  call void @__mir_bt_pop()\n" : '';
    }

    /**
     * Build a packed vec of the active call frames from `$global`
     * (@__mir_bt_name or @__mir_bt_line), innermost first (index depth-1 → 0);
     * lastValue ← the vec ptr as i64. Shared by the backtrace builtin and the
     * Throwable trace capture.
     */
    private function emitBtVec(string $global): string
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
            return isset($this->locals->slots[$a->name]);
        }
        if ($a->kind === Node::KIND_PROPERTY_ACCESS) {
            $pa = $a;
            $cls = $pa->object->type->class ?? '';
            return $cls !== '' && isset($this->classes[$cls]);
        }
        if ($a->kind === Node::KIND_ARRAY_ACCESS) {
            return $this->arrayElemAddressable($a);
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

    /** Emit a reset to the saved arena position (read immediately after save). */
    private function emitArenaReset(): string
    {
        return '  call void @__mir_arena_restore(ptr ' . $this->arena->saveCurReg
            . ', i64 ' . $this->arena->saveUsedReg . ")\n";
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
        $out .= $this->coerceToPtr();
        $src = $this->lastValue;
        $cur = $this->ssa->allocReg();
        $out .= '  ' . $cur . ' = load ptr, ptr ' . $slot . "\n";
        $nx = $this->ssa->allocReg();
        $out .= '  ' . $nx . ' = call ptr @__mir_array_spread_into(ptr ' . $cur . ', ptr ' . $src . ")\n";
        $out .= '  store ptr ' . $nx . ', ptr ' . $slot . "\n";
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
