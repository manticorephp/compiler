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
 * Module-level emission: the preamble, the function bodies, `@main`, enum
 * tables, the uncaught-exception handler, and the fixed runtime helper text.
 *
 * A trait on the one {@see EmitLlvm} host — the split is by concern, so a reader
 * opens the file for the thing they are looking at instead of scrolling one
 * 8k-line class. State stays on the host and its collaborators.
 */
trait EmitLlvmModule
{
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
        $this->rt->needsRc = true;
        $this->rt->needsStrRc = true;
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
            // A prelude class's static prop is defined by EVERY module (the
            // prelude is compiled into each), so external linkage is
            // `ld: duplicate symbols` for any program linking stdlib.o — and if
            // it did link, two copies would be two independent counters. Same
            // treatment as every other shared mutable global here
            // ({@see Module::$globalIsPrelude}).
            $linkage = ($this->globalIsPrelude[$gi] ?? false) ? 'linkonce_odr ' : '';
            $gi = $gi + 1;
            $globalCells .= $gname . ' = ' . $linkage . 'global i64 ' . $this->globalInit($def) . "\n";
        }
        // Emit each interned string constant as a headered @.str.N
        // ({i64 -1, [L x i8]}); the rc word lets a heap string and a
        // literal share one layout so retain/release work on either.
        foreach ($this->pool->all() as $value => $id) {
            $out .= $this->strGlobalDef('@.str.' . (string)$id, (string)$value);
        }
        $out .= $globalCells;
        // Zero word: a null vec/assoc base (the empty-literal optimization
        // stores `[]` as a null ptr) is redirected here so a foreach length
        // load reads 0 instead of faulting.
        $out .= "@__mir_zero_word = internal global i64 0\n";
        if ($this->rt->needsBacktrace) {
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
        if ($this->rt->needsExceptions) {
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
            $out .= $this->emitJmpSlotGuard();
            // `$gen->throw($e)` pending injection: non-null = throw at the
            // next yield resume point (the suspended `yield` expression raises).
            if ($this->gen->throwUsed) {
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
        // The runtime's own libc demand, plus the per-builtin extras registered
        // in $libcExtra and the FFI externs. Keyed by symbol: declaring one
        // twice is a hard LLVM error, so everything routes through one map.
        // The compare cluster is mutually recursive: tagged_loose_eq / tagged_
        // compare route an ARRAY operand to __mir_array_loose_eq / __mir_array_
        // compare, which recurse back through tagged_* for the elements, and the
        // bool/null row of PHP's juggling table needs tagged_truthy. Emitting one
        // half alone leaves an undefined symbol, so any demand pulls the whole
        // cluster in — ahead of the libc decls below (which derive @strtod from
        // needsTaggedToFloat) and every runtime gate that its members depend on.
        if ($this->rt->needsTaggedEq || $this->rt->needsTaggedCompare) {
            $this->rt->needsTaggedEq      = true;
            $this->rt->needsTaggedCompare = true;
            $this->rt->needsTagged        = true;
            $this->rt->needsTaggedToFloat = true;
            $this->rt->needsTaggedTruthy  = true;
            // tagged_compare stringifies a number to compare it against a
            // NON-numeric string (PHP's `5 < "abc"`).
            $this->rt->needsTaggedToStr   = true;
            $this->rt->needsStrcmp        = true;
            $this->rt->needsStrtod        = true;
        }
        $decls = $this->rt->libcDecls(\Compile\Debug::$verify);
        foreach ($this->libcExtra as $sym => $line) { $decls[$sym] = $line; }
        foreach ($this->rtExterns as $sym => $line) { $decls[$sym] = $line; }
        foreach ($decls as $line) { $out .= $line . "\n"; }
        if ($this->rt->needsCliArgv) {
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
        if ($this->rt->needsEnviron) {
            // The process environment ($_SERVER / $_ENV). `environ` is the
            // POSIX `char **`: a NULL-terminated vector of "KEY=VALUE" strings,
            // provided by the C runtime of an EXECUTABLE on both Linux and
            // Darwin (a shared library on Darwin would need _NSGetEnviron —
            // we only ever emit executables). Count first, then index: that
            // mirrors the argc/argv pair above, so the PHP-side builder walks
            // a bounded range and never has to null-check a raw pointer.
            $out .= "@environ = external global ptr\n";
            $out .= "define i64 @manticore_env_count() {\nentry:\n";
            $out .= "  %e = load ptr, ptr @environ\n";
            $out .= "  br label %loop\n";
            $out .= "loop:\n";
            $out .= "  %i = phi i64 [ 0, %entry ], [ %i1, %next ]\n";
            $out .= "  %g = getelementptr inbounds ptr, ptr %e, i64 %i\n";
            $out .= "  %p = load ptr, ptr %g\n";
            $out .= "  %z = icmp eq ptr %p, null\n";
            $out .= "  br i1 %z, label %done, label %next\n";
            $out .= "next:\n";
            $out .= "  %i1 = add i64 %i, 1\n";
            $out .= "  br label %loop\n";
            $out .= "done:\n";
            $out .= "  ret i64 %i\n}\n";
            $out .= "define ptr @manticore_env_at(i64 %i) {\nentry:\n";
            $out .= "  %e = load ptr, ptr @environ\n";
            $out .= "  %g = getelementptr inbounds ptr, ptr %e, i64 %i\n";
            $out .= "  %p = load ptr, ptr %g\n";
            $out .= "  ret ptr %p\n}\n";
        }
        if ($this->rt->needsClock) {
            // time / microtime / hrtime, all off one clock_gettime. `struct
            // timespec` is { i64 tv_sec, i64 tv_nsec } on every 64-bit Linux and
            // Darwin, so a [2 x i64] alloca IS the struct — no per-OS layout.
            //
            // The argument is a LOGICAL clock (0 wall, else monotonic), because
            // CLOCK_MONOTONIC is 1 on Linux but 6 on Darwin. The OS is NOT
            // detected at compile time: `host_os()` rides the libc uname/calloc
            // bindings, whose bodies are EMPTY under the Zend seed — calling it
            // from an emitter is a hard crash the moment the seed compiles a
            // source that uses this builtin. So try Linux's id and fall back to
            // Darwin's: a wrong id is a clean EINVAL that leaves the buffer
            // untouched (verified on Darwin: id 1 → rc -1), never garbage.
            //
            // Seconds fold into the nanosecond count here, so the PHP side does
            // one division instead of reaching into the struct.
            $out .= "declare i32 @clock_gettime(i32, ptr)\n";
            $out .= "define i64 @manticore_clock_ns(i64 %logical) {\nentry:\n";
            $out .= "  %ts = alloca [2 x i64], align 8\n";
            $out .= "  %mono = icmp ne i64 %logical, 0\n";
            $out .= "  br i1 %mono, label %m1, label %wall\n";
            $out .= "wall:\n";
            $out .= "  %rcw = call i32 @clock_gettime(i32 0, ptr %ts)\n";
            $out .= "  br label %read\n";
            $out .= "m1:\n";
            $out .= "  %rc1 = call i32 @clock_gettime(i32 1, ptr %ts)\n";
            $out .= "  %bad = icmp ne i32 %rc1, 0\n";
            $out .= "  br i1 %bad, label %m6, label %read\n";
            $out .= "m6:\n";
            $out .= "  %rc6 = call i32 @clock_gettime(i32 6, ptr %ts)\n";
            $out .= "  br label %read\n";
            $out .= "read:\n";
            $out .= "  %sp = getelementptr inbounds [2 x i64], ptr %ts, i64 0, i64 0\n";
            $out .= "  %s = load i64, ptr %sp\n";
            $out .= "  %np = getelementptr inbounds [2 x i64], ptr %ts, i64 0, i64 1\n";
            $out .= "  %n = load i64, ptr %np\n";
            $out .= "  %sn = mul i64 %s, 1000000000\n";
            $out .= "  %t = add i64 %sn, %n\n";
            $out .= "  ret i64 %t\n}\n";
        }
        if ($this->rt->needsStdStreams) {
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
        // The cell echo / (string) helpers now format floats through
        // __mir_float_to_str (PHP scientific form), so pull it — and the rc
        // release the echo helper uses — in ahead of their own emission below.
        if ($this->rt->needsTaggedEcho || $this->rt->needsTaggedToStr) {
            $this->rt->needsFloatStr = true;
        }
        if ($this->rt->needsTaggedEcho) {
            $this->rt->needsStrRc = true;
        }
        $out .= $this->profileRuntime();
        $out .= $this->allocRuntime();
        if ($this->rt->needsFloatStr) {
            $out .= $this->floatToStrImpl('@__mir_float_to_str', '@__mir_str_alloc');
            if ($this->rt->needsArena) {
                $out .= $this->floatToStrImpl('@__mir_float_to_str_arena', '@__mir_str_alloc_arena');
            }
        }
        if ($this->rt->needsFloatShortest) {
            $out .= $this->floatShortestImpl();
        }
        if ($this->rt->needsIntToStr()) {
            $out .= $this->intToStrRuntime();
        }
        if ($this->rt->needsBoxInt()) {
            $out .= $this->boxIntRuntime();
        }
        if ($this->rt->needsTagged) {
            $out .= $this->taggedRuntime();
        }
        if ($this->rt->needsTaggedEcho) {
            $out .= $this->taggedEchoRuntime();
        }
        if ($this->rt->needsTaggedToStr) {
            $out .= $this->taggedToStrRuntime();
        }
        if ($this->rt->needsImplodeCell) {
            $out .= $this->implodeCellRuntime();
        }
        if ($this->rt->needsTaggedToInt) {
            $out .= $this->taggedToIntRuntime();
        }
        if ($this->rt->needsTaggedToFloat) {
            $out .= $this->taggedToFloatRuntime();
        }
        if ($this->rt->needsTaggedCompare) {
            $out .= $this->taggedCompareRuntime();
            $out .= $this->arrayCompareRuntime();
        }
        if ($this->rt->needsTaggedEq) {
            $out .= $this->taggedEqRuntime();
        }
        if ($this->rt->needsTaggedArith) {
            $out .= "declare {i64, i1} @llvm.sadd.with.overflow.i64(i64, i64)\n";
            $out .= "declare {i64, i1} @llvm.ssub.with.overflow.i64(i64, i64)\n";
            $out .= "declare {i64, i1} @llvm.smul.with.overflow.i64(i64, i64)\n";
            $out .= $this->taggedArithRuntime();
        }
        if ($this->rt->needsTaggedTruthy) {
            $out .= $this->taggedTruthyRuntime();
        }
        if ($this->rt->needsConcat) {
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
        if ($this->rt->needsCellKey) {
            $out .= $this->cellKeyRuntime();
        }
        $out .= $this->emitEnumTables();
        $out .= "\n";
        return $out;
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
        $this->ssa->reset();
        $this->frame->name = $fn->name;
        $this->frame->body = $fn->body;
        $this->frame->hasArena = false;
        $this->arena->vecAllocated = false;
        $this->arena->vecLocals = [];
        $this->locals->slots = [];
        $this->locals->globalBacked = [];
        $this->frame->mutatedVecLocals = [];
        $this->collectMutatedVecs($fn->body);
        $this->locals->collectStatics($fn->body);
        // Top-level (`__main`) vars named in any `global $x` share the
        // same `@g_x` cell so writes are visible inside functions.
        if ($fn->name === '__main') {
            foreach ($this->globalVarNames as $gname) {
                $this->locals->globalBacked[$gname] = '@g_' . $gname;
            }
        }
        $this->cf->reset();
        // By-ref params: the slot holds the caller's variable address;
        // loads/stores deref it.
        $this->locals->refLocals = [];
        foreach ($fn->params as $p) {
            if ($p->byRef) { $this->locals->refLocals[$p->name] = true; }
        }
        $this->frame->returnsByRef = $fn->returnsByRef;
        $this->frame->returnType = $fn->returnType;
        $this->frame->isClosure = false;

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
        $this->frame->isClosure = $isClosure;
        $body = '';
        // The built-in Throwable/Exception/Error hierarchy is identical
        // boilerplate in every module, so emit it `linkonce_odr` — that lets
        // a user object link against the prebuilt stdlib.o (which also carries
        // the prelude) without duplicate-symbol errors, and lets the linker
        // drop it when a program references no exceptions. No-op for a lone .o.
        //
        // DO NOT change this to `internal` without also making every module emit
        // its own copy: a program linked against stdlib.o does not always emit
        // the prelude functions it calls, and relies on coalescing to stdlib.o's
        // copy. Marking them internal makes the symbol vanish, the stub
        // generator fills it with `return 0`, and e.g. sort() silently becomes a
        // no-op — measured, not theoretical.
        //
        // linkonce_odr keeps ONE copy, so every copy must be identical. That is
        // an obligation on the passes, not on this linkage: see
        // InferScans::scanCallSiteRefParams, which no longer narrows a prelude
        // param from module-local call sites precisely because doing so gave
        // sort() an arena body in one object and a refcount body in another,
        // under this one symbol. Any future pass that specialises a body must
        // either skip prelude functions or encode the specialisation in the
        // mangled name, the way Monomorphize does with its $mono$ suffixes.
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
                $slot = $this->ssa->allocReg();
                $this->locals->slots[$cn] = $slot;
                $body .= '  ' . $slot . " = alloca i64\n";
                $gep = $this->ssa->allocReg();
                $body .= '  ' . $gep . ' = getelementptr inbounds i64, ptr %env, i64 ' . (string)($pi + 1) . "\n";
                $cv = $this->ssa->allocReg();
                $body .= '  ' . $cv . ' = load i64, ptr ' . $gep . "\n";
                $body .= '  store i64 ' . $cv . ', ptr ' . $slot . "\n";
            }
            for ($pi = $capCnt; $pi < \count($fn->params); $pi = $pi + 1) {
                $pp = $fn->params[$pi];
                $cn = $pp->name;
                $slot = $this->ssa->allocReg();
                $this->locals->slots[$cn] = $slot;
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
                $slot = $this->ssa->allocReg();
                $this->locals->slots[$p->name] = $slot;
                $body .= '  ' . $slot . " = alloca i64\n";
                $body .= '  store i64 %arg.' . $p->name . ', ptr ' . $slot . "\n";
                // PHP arrays are values: a by-VALUE array param the body mutates
                // in place (`$x[] = …` / `$x[$k] = …` / nested `$x[0][] = …`) must
                // not alias the caller's buffer. Copy it on entry so the mutation
                // is private. A KNOWN nested `vec[vec[…]]` deep-clones each level
                // (`arrayCopyDepth`); anything else — incl. a bare `array` hint
                // that erased to unknown, or a cell/obj/string element — takes a
                // FLAT copy (depth 0), sound for a scalar/string/obj/cell element
                // (a nested array under an unknown type would still leak, but that
                // needs the concrete type). Gated on the DECLARED array hint so a
                // string param's `$s[0]=…` char-write is never mis-copied. By-ref
                // keeps aliasing the caller.
                if (!$p->byRef && $p->arrayHinted
                    && $this->localMutatedAsArray($fn->body, $p->name)) {
                    $ld = $this->ssa->allocReg();
                    $body .= '  ' . $ld . ' = load i64, ptr ' . $slot . "\n";
                    $lp = $this->ssa->allocReg();
                    $body .= '  ' . $lp . ' = inttoptr i64 ' . $ld . " to ptr\n";
                    $cp = $this->ssa->allocReg();
                    if (($et = $p->type->element) !== null && $et->kind === Type::KIND_CELL) {
                        // vec[cell] / assoc[*,cell]: elements are all NaN-boxed, so
                        // a tag-aware copy separates each boxed-array element (a
                        // nested `$x[0][] = …` on a het `[[1,2], "s"]` would else
                        // share the inner array). Safe only here — raw vecs can't
                        // be tag-inspected (a large/neg int could look boxed).
                        $body .= '  ' . $cp . ' = call ptr @__mir_array_copy_cells(ptr ' . $lp . ")\n";
                    } else {
                        $depth = $this->arrayCopyDepth($p->type);
                        if ($depth < 0) { $depth = 0; }
                        $body .= '  ' . $cp . ' = call ptr @__mir_array_copy_deep(ptr ' . $lp
                              . ', i64 ' . (string)$depth . ")\n";
                    }
                    $ci = $this->ssa->allocReg();
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
        $this->locals->byRefCaptured = [];
        $this->locals->collectByRefCaptured($fn->body);
        foreach ($this->locals->byRefCaptured as $bname => $_) {
            if (isset($paramNames[$bname])) { continue; }
            if (!isset($this->locals->slots[$bname])) { continue; }
            if (isset($this->locals->refLocals[$bname])) { continue; }
            $box = $this->ssa->allocReg();
            $body .= '  ' . $box . " = call ptr @__mir_alloc(i64 8)\n";
            $body .= '  store i64 0, ptr ' . $box . "\n";
            $bi = $this->ssa->allocReg();
            $body .= '  ' . $bi . ' = ptrtoint ptr ' . $box . " to i64\n";
            $body .= '  store i64 ' . $bi . ', ptr ' . $this->locals->slots[$bname] . "\n";
            $this->locals->refLocals[$bname] = true;
        }
        // Stamp the correct backtrace frame name for a method now that the
        // callee identity is exact ($fn->name is stable — it drives the define
        // header). The caller pushed a bare method-name placeholder because a
        // stable receiver class isn't available at the call site under the
        // self-host. Overwrites this frame's name slot (index depth-1).
        if ($this->rt->needsBacktrace && isset($this->methodDisplay[$fn->name])) {
            $body .= $this->btNameFix($this->methodDisplay[$fn->name]);
        }
        $body .= $this->emitNode($fn->body);
        $body .= "  ret i64 0\n";
        return $header . $body . "}\n\n";
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
     * `@__mir_jmp_slot` — slot → byte offset into `@__mir_jmp_stack`, fataling
     * when the slot is out of range instead of returning an offset that setjmp
     * would write 192 bytes past the end of.
     *
     * Linkage is left to {@see EmitLlvm::linkonceRuntime}, which promotes every
     * `define` in the preamble to linkonce_odr — an explicit `internal` here
     * would come out as the invalid `define linkonce_odr internal`. Coalescing
     * is safe: the body is a pure function of its argument and identical in
     * every module, exactly like the neighbouring runtime helpers.
     */
    private function emitJmpSlotGuard(): string
    {
        $this->libcExtra['exit'] = 'declare void @exit(i32)';
        $this->libcExtra['dprintf'] = 'declare i32 @dprintf(i32, ptr, ...)';
        $msg = 'PHP Fatal error:  Maximum try nesting level (16) exceeded';
        $len = \strlen($msg) + 2; // + "\n" + NUL
        $out = '@.fmt.jmpof = private unnamed_addr constant [' . (string)$len . ' x i8] c"'
             . $msg . '\0A\00", align 1' . "\n";
        $out .= "define i64 @__mir_jmp_slot(i64 %s) {\nentry:\n";
        $out .= "  %lo = icmp slt i64 %s, 0\n";
        $out .= "  %hi = icmp sge i64 %s, 16\n";
        $out .= "  %bad = or i1 %lo, %hi\n";
        $out .= "  br i1 %bad, label %oflow, label %ok\n";
        $out .= "oflow:\n";
        $out .= "  %p = call i32 (i32, ptr, ...) @dprintf(i32 2, ptr @.fmt.jmpof)\n";
        $out .= "  call void @exit(i32 255)\n";
        $out .= "  unreachable\n";
        $out .= "ok:\n";
        $out .= "  %o = mul i64 %s, 256\n";
        $out .= "  ret i64 %o\n}\n";
        return $out;
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
        $this->rt->needsCliArgv = true;
        $header = "define i32 @main(i32 %argc, ptr %argv) {\nentry:\n";
        if ($this->rt->needsExceptions) {
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
        $ac = $this->ssa->allocReg();
        $header .= '  ' . $ac . ' = sext i32 %argc to i64' . "\n";
        $header .= '  store i64 ' . $ac . ", ptr @__manticore_argc\n";
        $header .= "  store ptr %argv, ptr @__manticore_argv\n";
        $body = $this->preallocateLocals($fn->body);
        $body .= $this->initRcObjSlots($fn->body);
        // A global cell whose default is not a link-time constant (an array
        // literal on a static property) is built HERE, before any top-level
        // statement, so the first read/append sees a real array and not 0.
        $body .= $this->emitGlobalRuntimeInits();
        $body .= $this->emitNode($fn->body);
        $body .= "  ret i32 0\n";
        return $header . $body . "}\n\n";
    }

    /**
     * Materialise every global cell whose default {@see globalInit} could not
     * render as a link-time constant. Only a static property can carry one
     * (every other `addGlobalCell` caller registers `IntConst(0)`), so this is
     * the `public static array $xs = [...]` initialiser and nothing else.
     */
    private function emitGlobalRuntimeInits(): string
    {
        $out = '';
        $gi = 0;
        foreach ($this->globalNames as $gname) {
            $def = $this->globalDefaults[$gi];
            $gi = $gi + 1;
            if ($this->globalInitIsConst($def)) { continue; }
            $out .= $this->emitNode($def);
            $out .= $this->coerceToI64();
            $out .= '  store i64 ' . $this->lastValue . ', ptr ' . $gname . "\n";
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
        foreach ($this->frame->rcObjLocals as $name => $mo) {
            if ($name === $returnedLocal) { continue; }
            if (isset($this->frame->transferredLocals[$name])) { continue; }
            if (!isset($this->locals->slots[$name])) { continue; }
            $out .= $this->rcReleaseSlot($this->locals->slots[$name], $this->rcReleaseFlavor($mo));
        }
        return $out;
    }

    private function emitReturn(Return_ $n): string
    {
        $r = $n;
        $v = $r->value;
        // Inside a generator, `return` FINISHES it (state = -1, resume → 0).
        // The return value (if any) is stashed in `retval` for getReturn().
        if ($this->gen->inGenerator) {
            $out = '';
            if ($v !== null) {
                $out .= $this->emitNode($v);
                $out .= $this->coerceToI64();
                $out .= '  store i64 ' . $this->lastValue . ', ptr ' . $this->gen->retvalPtr . "\n";
            }
            $out .= '  store i64 -1, ptr ' . $this->gen->statePtr . "\n";
            // Same slot hand-back as {@see finishReturn} — this branch exits
            // before it, so a `return` inside a generator's try leaked one slot
            // per call until the depth hit the 16-slot wall. The reload comes
            // from the frame: a `yield` in the try means the resume switch
            // branched past the block that defined the entry reg.
            $out .= $this->restoreJmpDepth($this->cf->returnDepthReg(), $this->cf->returnDepthSlot());
            return $out . "  ret i64 0\n" . $this->emitDeadLabel();
        }
        // Close the frame arena before every exit, so confined values
        // are freed on the path actually taken (the plan's trailing
        // arena_leave only covers fall-through). The return value is
        // escaping (RcHeap, heap-allocated), never arena, so freeing
        // the arena here can't touch it.
        $leave = $this->frame->hasArena ? "  call void @__mir_arena_leave()\n" : '';
        // Drop every owned RcHeap obj local on this return path, except
        // the one being returned (ownership transfers to the caller). The
        // trailing fall-through release covers paths with no `return`.
        $returnedLocal = ($v !== null && $v->kind === Node::KIND_LOAD_LOCAL)
            ? $v->name : null;
        $leave .= $this->emitRcReturnCleanup($returnedLocal);
        if ($v === null) {
            return $this->finishReturn('', '0', $leave);
        }
        // By-ref return: yield the *address* of the returned lvalue as i64.
        // `return $n` (a by-ref param forwards its held address, a plain local
        // returns its slot address) or `return $this->prop` (GEP to the field
        // slot). An unaddressable value falls through to the normal value path.
        if ($this->frame->returnsByRef) {
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
        if ($this->frame->isClosure && $this->isCellBoxableArg($v->type)) {
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
        if ($this->frame->isClosure && $v->type->kind === Type::KIND_UNKNOWN) {
            $this->rt->needsTagged = true;
            $out .= $this->boxLastByRepr();
            return $this->finishReturn($out, $this->lastValue, $leave);
        }
        // The declared return is a CELL-element array but this arm still holds a
        // CONCRETE-element one: the arms disagreed (`return [false,'loc']` beside
        // `return ['body','']`), so {@see NarrowReturns::joinElem} typed the fn
        // `vec[cell]` and every arm must actually BE cell-element — otherwise the
        // reader unboxes this arm's raw string pointers by tag. Rebuild it with
        // each element boxed, left raw (an array slot travels raw).
        if ($this->needsCellify($this->frame->returnType, $v->type)) {
            $out .= $this->emitCellifyArrayRaw($v->type->element);
            // The rebuild is a FRESH array (+1, owned by the caller — no retain,
            // unlike a borrowed passthrough), but it leaves a raw `ptr`: the ABI
            // returns a uniform i64, so it rides the carrier like every other
            // pointer return.
            $out .= $this->coerceToI64();
            return $this->finishReturn($out, $this->lastValue, $leave);
        }
        // A `mixed` / union (cell) return boxes the value to a tagged
        // cell unless it already is one.
        if ($this->frame->returnType !== null
            && $this->frame->returnType->kind === Type::KIND_CELL
            && $v->type->kind !== Type::KIND_CELL) {
            $out .= $this->boxToCell($v->type);
        } else {
            // A cell value returned where the declared type is concrete
            // (`return $mixed[$i]` from a `: int` fn) must be unboxed — else the
            // tagged bits flow back as the result (a boxed int read as a raw
            // i64). Mirrors the cell→param unboxing.
            if ($v->type->kind === Type::KIND_CELL && $this->frame->returnType !== null) {
                $out .= $this->unboxCellToType($this->frame->returnType);
            }
            // ABI: every fn returns i64. Coerce float / ptr through
            // the i64 carrier.
            $out .= $this->coerceToI64();
            // +1 return convention: a borrowed obj (param / alias /
            // property / array read) is retained so the caller owns a
            // reference. Owned producers (`new`, call return) and
            // owned-local transfers are already +1. The declared return type
            // is the fallback: it is what the CALLER assumes ({@see
            // ownershipReturnType}).
            if ($this->isBorrowedObjReturn($v, $returnedLocal)) {
                $out .= $this->rcRetainByType($v, $this->lastValue, $this->frame->returnType);
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
        if ($this->cf->hasFinally()) {
            $saved = $this->cf->takeFinally();
            foreach (\array_reverse($saved) as $body) {
                foreach ($body as $s) { $out .= $this->emitNode($s); $out .= $this->emitDiscardedCallRelease($s); }
            }
            $this->cf->restoreFinally($saved);
        }
        // A `return` out of a try branches past the fall-through pop, so the
        // try's jmp slot would stay claimed for the rest of the PROCESS (the
        // depth is a global). Give it back — after the finallys above, which run
        // inside the try region and manage their own depth.
        return $out . $leave . $this->restoreJmpDepth($this->cf->returnDepthReg(), $this->cf->returnDepthSlot())
             . '  ret i64 ' . $valReg . "\n" . $this->emitDeadLabel();
    }

    /**
     * The type the CALLER will assume for the returned value.
     *
     * A read out of an element-type-erased array (`return $a[$i]` where `$a` is
     * a bare-`array` param) types the expression UNKNOWN/CELL, but the caller
     * takes ownership per the fn's DECLARED return type. Deciding the +1 from
     * the erased expression type makes the two sides disagree: the callee skips
     * the retain while the caller still releases, freeing an object the array
     * still owns (double-free → SIGTRAP). So ownership follows the declared type
     * whenever the expression's own type carries none.
     */
    private function ownershipReturnType(Node $v): Type
    {
        $tk = $v->type->kind;
        if ($tk !== Type::KIND_UNKNOWN && $tk !== Type::KIND_CELL) { return $v->type; }
        if ($this->frame->returnType === null) { return $v->type; }
        return $this->frame->returnType;
    }

    /**
     * A CELL-element array slot receiving a CONCRETE-element array value — the
     * cellify boundary ({@see EmitLlvmBuiltins::emitCellifyArrayRaw}). The exact
     * mirror of the de-cellify direction {@see EmitLlvmBuiltins::needsDeCellify}
     * plants at a store.
     *
     * Both sides must be arrays of the SAME shape (vec/vec, assoc/assoc): the
     * rebuild walks keys, so a vec↔assoc pair is not a repr change but a shape
     * change, which no arm of a return join produces. An `unknown` element is
     * NOT cellified — nothing is known to box, and an erased array must stay raw
     * (the `cow2` carve-out).
     */
    private function needsCellify(?Type $slot, ?Type $val): bool
    {
        if ($slot === null || $val === null) { return false; }
        if (!$slot->isArray() || !$val->isArray()) { return false; }
        if ($slot->isAssoc() !== $val->isAssoc()) { return false; }
        $se = $slot->element;
        $ve = $val->element;
        if ($se === null || $ve === null) { return false; }
        if ($se->kind !== Type::KIND_CELL) { return false; }
        return $ve->kind !== Type::KIND_CELL && $ve->kind !== Type::KIND_UNKNOWN;
    }

    /** Whether an obj/vec return value is a borrowed reference (needs +1). */
    private function isBorrowedObjReturn(Node $v, ?string $returnedLocal): bool
    {
        $t = $this->ownershipReturnType($v);
        $tk = $t->kind;
        // vec AND assoc: both are one rc'd buffer. Testing only isVec() (an
        // array that is NOT string-keyed) left a borrowed ASSOC return at +0
        // while every caller assumed +1 — `$t = $p->all(); count($t)` read a
        // buffer the object still owned and had already freed.
        $isArr = $t->isVec() || $t->isAssoc();
        if ($tk !== Type::KIND_OBJ && !$isArr
            && $tk !== Type::KIND_STRING) { return false; }
        if ($tk === Type::KIND_OBJ && ($this->objTypeIsStruct($t)
            || $this->isClosureClass($t->class ?? ''))) { return false; }
        $k = $v->kind;
        if ($k === Node::KIND_CALL || $k === Node::KIND_METHOD_CALL
            || $k === Node::KIND_STATIC_CALL || $k === Node::KIND_INVOKE) {
            return false; // owned producer — already +1
        }
        if ($tk === Type::KIND_OBJ && ($k === Node::KIND_NEW_OBJ || $k === Node::KIND_CLONE)) { return false; }
        if ($isArr && ($k === Node::KIND_ARRAY_LIT || $k === Node::KIND_SPREAD)) { return false; }
        // A concat is an owned +1; a literal is immortal — neither needs a
        // borrow retain. (rcRetainByType also no-ops these, but short-
        // circuit here so the convention reads clearly.)
        if ($tk === Type::KIND_STRING
            && ($k === Node::KIND_CONCAT || $k === Node::KIND_STRING_CONST)) { return false; }
        if ($k === Node::KIND_LOAD_LOCAL && $returnedLocal !== null
            && isset($this->frame->rcObjLocals[$returnedLocal])) {
            return false; // transfer of an owned local
        }
        return true; // param / alias / property / array read — borrow
    }
}
