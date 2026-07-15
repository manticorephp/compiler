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
 * Calls: free functions, closures, invokes, FFI wrappers, and preparing the
 * argument list against the callee's signature (by-ref, tagged, default padding).
 *
 * A trait on the one {@see EmitLlvm} host — the split is by concern, so a reader
 * opens the file for the thing they are looking at instead of scrolling one
 * 8k-line class. State stays on the host and its collaborators.
 */
trait EmitLlvmCalls
{
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
                $r = $this->ssa->allocReg();
                $out .= '  ' . $r . ' = inttoptr i64 ' . $src . " to ptr\n";
                $cargs[] = 'ptr ' . $r;
            } elseif ($ct === 'double') {
                $r = $this->ssa->allocReg();
                $out .= '  ' . $r . ' = bitcast i64 ' . $src . " to double\n";
                $cargs[] = 'double ' . $r;
            } elseif ($ct === 'i1') {
                $r = $this->ssa->allocReg();
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
            $r = $this->ssa->allocReg();
            $out .= '  ' . $r . ' = call ' . $ret . ' @' . $cSym . '(' . $callArgs . ")\n";
            if ($ret === 'ptr') {
                $ri = $this->ssa->allocReg();
                $out .= '  ' . $ri . ' = ptrtoint ptr ' . $r . " to i64\n";
                $out .= '  ret i64 ' . $ri . "\n";
            } elseif ($ret === 'double') {
                $ri = $this->ssa->allocReg();
                $out .= '  ' . $ri . ' = bitcast double ' . $r . " to i64\n";
                $out .= '  ret i64 ' . $ri . "\n";
            } elseif ($ret === 'i1') {
                $ri = $this->ssa->allocReg();
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
    private function emitClosure(Closure_ $n): string
    {
        $cl = $n;
        $cnt = \count($cl->captures);
        // Layout: [fn_ptr, cap0, cap1, ...]. The fn ptr at slot 0 lets a
        // closure invoked through a `Closure`-typed value (returned /
        // passed) dispatch indirectly; captures follow at slot 1+.
        $sz = 8 * (1 + $cnt);
        $buf = $this->ssa->allocReg();
        $out = '  ' . $buf . ' = call ptr @__mir_alloc(i64 ' . (string)$sz . ")\n";
        $fnName = '__closure_' . (string)$cl->id;
        $fp = $this->ssa->allocReg();
        $out .= '  ' . $fp . ' = ptrtoint ptr @manticore_' . $this->mangle($fnName) . " to i64\n";
        $out .= '  store i64 ' . $fp . ', ptr ' . $buf . "\n";
        $i = 0;
        foreach ($cl->captures as $c) {
            if (($cl->captureByRef[$i] ?? false) && $c->kind === Node::KIND_LOAD_LOCAL) {
                // `use (&$x)`: pack the ADDRESS of $x's slot so the closure
                // body (a byRef param → refLocal) reads/writes the original.
                // Already-ref enclosing locals hold the address; plain locals
                // take the slot address. No rc retain on a raw address.
                $name = $c->name;
                $capV = $this->ssa->allocReg();
                if (isset($this->locals->refLocals[$name])) {
                    $out .= '  ' . $capV . ' = load i64, ptr ' . $this->locals->slots[$name] . "\n";
                } else {
                    $out .= '  ' . $capV . ' = ptrtoint ptr ' . $this->locals->slots[$name] . " to i64\n";
                }
            } else {
                $out .= $this->emitNode($c);
                $out .= $this->coerceToI64();
                $capV = $this->lastValue;
                // The closure owns a reference to each captured obj.
                $out .= $this->rcRetainByType($c, $capV, null, 1);
            }
            $gep = $this->ssa->allocReg();
            $out .= '  ' . $gep . ' = getelementptr inbounds i64, ptr ' . $buf . ', i64 ' . (string)($i + 1) . "\n";
            $out .= '  store i64 ' . $capV . ', ptr ' . $gep . "\n";
            $i = $i + 1;
        }
        $this->lastValue = $buf;
        $this->lastValueType = 'ptr';
        return $out;
    }

    /** `$closure(args)` → load captures from the struct, call __closure_N. */
    private function emitInvoke(Invoke_ $n): string
    {
        $iv = $n;
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
        // closure entry unboxes a concrete-scalar param). Without this a
        // cell-typed param reads the raw arg bits → a string renders as its
        // pointer.
        //
        // A typed ARRAY arg is CELLIFIED (its values boxed) ONLY when the
        // callee param is ERASED (cell/unknown) — the same boundary rule the
        // scalar box/unbox already follows. A typed `assoc[string,int]` handed
        // to an untyped `$p` reads back RAW via `$p["k"]` (the read types cell
        // but the storage is raw), so the callee misboxes it. Gating on the
        // param type — not the arg type — is the fix: a callee whose param is a
        // TYPED array (an array_map-style callback) still gets the raw array it
        // expects, so cellifying blindly (which crashed self-host) is avoided.
        $known = $fn !== '' && isset($this->closureCaptures[$fn]);
        $calleeParams = $known ? ($this->sigs->paramTypes[$fn] ?? []) : [];
        foreach ($iv->args as $ai => $a) {
            $out .= $this->emitNode($a);
            $pt = $calleeParams[$ai] ?? null;
            // Cellify only for a KNOWN callee whose param is provably erased
            // (cell/unknown). A dynamic callee (`callable`) can't be gated — its
            // param might be a TYPED array (an array_map-style callback) that
            // needs the raw array, and cellifying it blindly corrupts the
            // element reads (it crashes self-host). So the dynamic-callback case
            // — a `usort($x, fn($a,$b)=>$cmp($a["k"],$b["k"]))` with an int-arith
            // `$cmp` — is still open, pending a representation discriminator.
            $paramErased = $known && $pt !== null
                && ($pt->kind === Type::KIND_CELL || $pt->kind === Type::KIND_UNKNOWN);
            if ($this->isCellBoxableArg($a->type)) {
                $out .= $this->boxToCell($a->type);
            } elseif ($paramErased && $a->type->isArray() && $this->hasConcreteScalarElem($a->type)) {
                $out .= $this->boxToCell($a->type);
            } else {
                $out .= $this->coerceToI64();
            }
            $argList .= ', i64 ' . $this->lastValue;
            $argTypes .= ', i64';
        }
        $reg = $this->ssa->allocReg();
        if ($known) {
            $out .= '  ' . $reg . ' = call i64 @manticore_' . $this->mangle($fn) . '(' . $argList . ")\n";
        } else {
            // Dynamic dispatch: load the fn ptr from struct slot 0 and call
            // indirectly (the callee is a `Closure`-typed value whose
            // concrete __closure_N isn't known statically).
            $fpi = $this->ssa->allocReg();
            $out .= '  ' . $fpi . ' = load i64, ptr ' . $struct . "\n";
            $fp = $this->ssa->allocReg();
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
            $fname = $s->function;
            if (!isset($this->sigs->paramTypes[$fname])) { return ''; }
            // A by-ref-returning fn yields an address, not an owned value.
            if ($this->sigs->returnsByRef[$fname] ?? false) { return ''; }
        } elseif ($k !== Node::KIND_METHOD_CALL && $k !== Node::KIND_STATIC_CALL) {
            return '';
        }
        $flavor = $this->discardReleaseFlavor($s->type);
        if ($flavor === '') { return ''; }
        return $this->rcReleaseReg($this->lastValue, $flavor);
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
        $ptypes = $this->sigs->paramTypes[$fnKey] ?? [];
        $pcount = \count($ptypes);
        if ($firstMissingIdx >= $pcount) { return ''; }
        $pdefs = $this->sigs->paramDefaults[$fnKey] ?? [];
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

    private function emitByRefArg(Node $a): string
    {
        return $this->byRefAddrOf($a) ?? '';
    }

    private function emitCall(Call $n): string
    {
        $c = $n;
        $b = $this->emitBuiltin($c);
        if ($b !== null) { return $b; }
        $out = '';
        $argList = '';
        $first = true;
        $mask = $this->sigs->refParams[$c->function] ?? [];
        $tmask = $this->sigs->taggedParams[$c->function] ?? [];
        $ptypes = $this->sigs->paramTypes[$c->function] ?? [];
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
                $operand = $a->operand;
                $out .= $this->emitNode($operand);
                $out .= $this->coerceToPtr();
                $arr = $this->lastValue;
                $elemType = $operand->type->element ?? null;
                $nparams = \count($ptypes);
                $k = $ai;
                while ($k < $nparams) {
                    if (!$first) { $argList .= ', '; }
                    $first = false;
                    $ev = $this->ssa->allocReg();
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
                $tmp = $this->ssa->allocReg();
                $out .= '  ' . $tmp . " = alloca i64\n";
                $out .= $this->emitNode($a);
                $out .= $this->coerceToI64();
                $out .= '  store i64 ' . $this->lastValue . ', ptr ' . $tmp . "\n";
                $addr = $this->ssa->allocReg();
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
                    $d = $this->ssa->allocReg();
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
        $reg = $this->ssa->allocReg();
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
        if ($this->rt->needsBacktrace && \substr($mangled, 0, 13) !== 'manticore_rt_') {
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
        if (!($this->sigs->returnsByRef[$c->function] ?? false)) {
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
        if (($this->sigs->returnsByRef[$c->function] ?? false) && !$this->rawRefCall) {
            $p = $this->ssa->allocReg();
            $out .= '  ' . $p . ' = inttoptr i64 ' . $reg . " to ptr\n";
            $dv = $this->ssa->allocReg();
            $out .= '  ' . $dv . ' = load i64, ptr ' . $p . "\n";
            $this->lastValue = $dv;
            $reg = $dv;
        }
        // If the inferred return type is float, bitcast the i64
        // back to a usable double for the caller side.
        if ($n->type->kind === Type::KIND_FLOAT) {
            $regF = $this->ssa->allocReg();
            $out .= '  ' . $regF . ' = bitcast i64 ' . $reg . " to double\n";
            $this->lastValue = $regF;
            $this->lastValueType = 'double';
        }
        return $out;
    }
}
