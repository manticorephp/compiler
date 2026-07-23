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
        // Linkage mirrors emitFunction (EmitLlvmModule): a PRELUDE FFI wrapper
        // (e.g. `__mc_libc_fclose`, declared in prelude/resource.php) is compiled
        // into EVERY module — both a user program's `.o` AND the prebuilt stdlib
        // `.o` — so external linkage duplicate-symbols on GNU ld (Apple ld64
        // coalesces; GNU ld errors "multiple definition"). linkonce_odr merges the
        // identical bodies. A NON-prelude FFI binding (`Runtime\Libc\strcmp`) is
        // defined in ONE module and referenced across the .o boundary; it must stay
        // external, or `--gc-sections` drops the linkonce copy and the reference
        // goes undefined. Bodies are deterministic per C symbol → ODR-safe.
        $ffiLinkage = $fn->isPrelude ? 'linkonce_odr ' : '';
        $out = 'define ' . $ffiLinkage . 'i64 @manticore_' . $this->mangle($fn->name) . '(' . $paramSig . ") {\nentry:\n";
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
        // A VARIADIC C function: declare and CALL with an explicit variadic
        // function type — `ret (t0, …, ...)` — so the target ABI places the
        // variadic args correctly (Darwin arm64 puts them on the stack, not in
        // registers; a plain fixed-arity call hands the callee register garbage
        // where it does va_arg). The value is the count of NAMED params (before
        // the `...`). Keyed by C symbol rather than carried on FunctionDef: a new
        // FunctionDef field mistyped every FFI wrapper's return-type read under
        // the self-built compiler, so the small, controlled set of variadic libc
        // symbols we bind is listed here instead.
        // The type token between `call` and the callee, and the declare's param
        // list. For a NON-variadic symbol these are just `$ret` and the plain
        // param types (unchanged from the original path). For a variadic C
        // function they become the FULL function type `ret (t0, …, ...)` and a
        // `..."-terminated param list, so LLVM binds the extra args as varargs and
        // applies the target's variadic ABI (Darwin arm64 puts varargs on the
        // stack; a plain fixed-arity call hands the callee register garbage where
        // it does va_arg). Both branches assign PLAIN STRINGS — a `?string`
        // (null-initialised then maybe-set) local read as garbage under the
        // self-built compiler (the string was read as a raw pointer in `call
        // <ptr> @fclose`). $variadicFixed = named-param count, or -1.
        $variadicFixed = $this->ffiVariadicFixed($cSym);
        $callTypeTok = $ret;
        $declParams = \implode(', ', $fn->ffiParamCTypes);
        if ($variadicFixed >= 0) {
            $named = \array_slice($fn->ffiParamCTypes, 0, $variadicFixed);
            $sig = \implode(', ', $named) . ($named === [] ? '' : ', ') . '...';
            $callTypeTok = $ret . ' (' . $sig . ')';
            $declParams = $sig;
        }
        // cli_argc/argv are DEFINED in the preamble (they read the argc/argv
        // captured by main), so don't also declare them — a declare + define
        // of one symbol is an LLVM redefinition error.
        if ($cSym !== 'manticore_cli_argc' && $cSym !== 'manticore_cli_argv') {
            $this->libcExtra[$cSym] = 'declare ' . $ret . ' @' . $cSym
                . '(' . $declParams . ')';
        }
        $callArgs = \implode(', ', $cargs);
        if ($ret === 'void') {
            $out .= '  call ' . $callTypeTok . ' @' . $cSym . '(' . $callArgs . ")\n";
            $out .= "  ret i64 0\n";
        } else {
            $r = $this->ssa->allocReg();
            $out .= '  ' . $r . ' = call ' . $callTypeTok . ' @' . $cSym . '(' . $callArgs . ")\n";
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
     * The NAMED-param count for a variadic libc symbol (the count before the C
     * `...`), or -1 when the symbol is not variadic. Drives the variadic call
     * type in {@see emitFfiWrapper}. Keyed by C symbol because a FunctionDef
     * field for this mistyped every wrapper's return-type read under the
     * self-built compiler. The set of variadic C functions the FFI layer binds
     * is small and controlled (all in Runtime\Libc), so it lives here.
     */
    private function ffiVariadicFixed(string $cSym): int
    {
        if ($cSym === 'fcntl') { return 2; }   // int fcntl(int fd, int cmd, ...)
        return -1;
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
    /** `$f(args)` / call_user_func($f, args) with `$f` a runtime function-name
     *  string. strcmp the name against each arity-compatible FREE user function
     *  and reuse the normal Call path for the match, boxing the result to a
     *  cell; an unmatched name yields null. Method keys (`Class__method`) and
     *  `__main` are excluded (the `__` marker). One arm runs, so re-emitting
     *  args per arm evaluates them once at runtime. */
    private function emitDynFnCall(Invoke_ $iv): string
    {
        $this->rt->needsStrcmp = true;
        $out = $this->emitNode($iv->callee);
        $out .= $this->coerceToPtr();
        $keyP = $this->lastValue;
        $argc = \count($iv->args);
        // A `...$arr` spread makes the runtime arg count unknown. HOIST the
        // fixed-prefix arg values and the spread array pointer ONCE, before the
        // candidate loop, so they dominate every sibling hit block — then each
        // candidate's call is built DIRECTLY (see below), never by re-emitting a
        // `Call` per block (that route runs the spread through emitCall/
        // emitBuiltin in each block and a Spread_ the builtins don't handle
        // reads a stale value → an SSA dominance violation). Only a single
        // trailing spread is supported.
        $spreadIdx = -1;
        foreach ($iv->args as $i => $a) {
            if ($a->kind === Node::KIND_SPREAD) {
                if ($spreadIdx !== -1 || $i !== \count($iv->args) - 1) {
                    throw new \RuntimeException('only a single trailing spread into a dynamic function-name callable is supported');
                }
                $spreadIdx = $i;
            }
        }
        $hasSpread = $spreadIdx !== -1;
        $numFixed = $hasSpread ? $spreadIdx : $argc;
        $fixedRegs = [];
        $spreadArr = '';
        if ($hasSpread) {
            for ($fi = 0; $fi < $numFixed; $fi = $fi + 1) {
                $out .= $this->emitNode($iv->args[$fi]);
                $out .= $this->coerceToI64();
                $fixedRegs[] = $this->lastValue;
            }
            $out .= $this->emitNode($iv->args[$spreadIdx]->operand);
            $out .= $this->coerceToPtr();
            $spreadArr = $this->lastValue;
        }
        $res = $this->ssa->allocReg();
        $out .= '  ' . $res . " = alloca i64\n";
        $out .= '  store i64 0, ptr ' . $res . "\n";
        $endL = $this->ssa->allocLabel('dynf.end');
        foreach ($this->sigs->returnType as $fname => $rt) {
            if (\strpos($fname, '__') !== false) { continue; }
            $ptypes = $this->sigs->paramTypes[$fname] ?? [];
            $pdefs = $this->sigs->paramDefaults[$fname] ?? [];
            $tot = \count($ptypes);
            $req = 0;
            for ($pi = 0; $pi < $tot; $pi = $pi + 1) {
                if (($pdefs[$pi] ?? null) === null) { $req = $req + 1; }
            }
            // With a spread the runtime argc is unknown; only the fixed prefix
            // gives a static lower bound on the callee arity.
            if ($hasSpread) {
                if ($tot < $numFixed) { continue; }
            } elseif ($argc < $req || $argc > $tot) {
                continue;
            }
            $hitL = $this->ssa->allocLabel('dynf.hit');
            $nextL = $this->ssa->allocLabel('dynf.next');
            $cmp = $this->ssa->allocReg();
            $out .= '  ' . $cmp . ' = call i32 @strcmp(ptr ' . $keyP . ', ptr ' . $this->litStr($fname) . ")\n";
            $eq = $this->ssa->allocReg();
            $out .= '  ' . $eq . ' = icmp eq i32 ' . $cmp . ", 0\n";
            $out .= '  br i1 ' . $eq . ', label %' . $hitL . ', label %' . $nextL . "\n";
            $out .= $hitL . ":\n";
            if ($hasSpread) {
                // Build the call directly: fixed prefix from the hoisted regs,
                // the rest read from the spread array (element k → param
                // numFixed+k). Matches emitCall's fixed-arity spread contract —
                // the array must supply the callee's remaining params.
                $argList = '';
                for ($pi = 0; $pi < $tot; $pi = $pi + 1) {
                    if ($pi > 0) { $argList .= ', '; }
                    if ($pi < $numFixed) {
                        $argList .= 'i64 ' . $fixedRegs[$pi];
                        continue;
                    }
                    $ev = $this->ssa->allocReg();
                    $out .= '  ' . $ev . ' = call i64 @__mir_array_value_at(ptr ' . $spreadArr
                          . ', i64 ' . (string)($pi - $numFixed) . ")\n";
                    $argList .= 'i64 ' . $ev;
                }
                $reg = $this->ssa->allocReg();
                $out .= '  ' . $reg . ' = call i64 @manticore_' . $this->mangle($fname) . '(' . $argList . ")\n";
                $this->lastValue = $reg;
                $this->lastValueType = 'i64';
            } else {
                $call = new \Compile\Mir\Call($fname, $iv->args, $rt);
                $out .= $this->emitNode($call);
            }
            $out .= $this->boxToCell($rt);
            $out .= '  store i64 ' . $this->lastValue . ', ptr ' . $res . "\n";
            $out .= '  br label %' . $endL . "\n";
            $out .= $nextL . ":\n";
        }
        $out .= '  br label %' . $endL . "\n";
        $out .= $endL . ":\n";
        $loaded = $this->ssa->allocReg();
        $out .= '  ' . $loaded . ' = load i64, ptr ' . $res . "\n";
        $this->lastValue = $loaded;
        $this->lastValueType = 'i64';
        return $out;
    }

    private function emitInvoke(Invoke_ $n): string
    {
        $iv = $n;
        // `$o->$m(args)` parses as Invoke(DynProp): the callee is not a value to
        // invoke but a dynamic METHOD reference. Dispatch on the runtime method
        // name against the receiver class's methods.
        if ($iv->callee instanceof DynProp_) {
            // Pass the callee as a DynProp_-typed arg: reading `->object`/`->name`
            // off the base-Node `$iv->callee` resolves by the WRONG offset under
            // the native self-build (Node has neither field), so a typed param is
            // load-bearing here.
            return $this->emitDynMethodCall($iv->callee, $iv);
        }
        // A string-typed callee names a FREE FUNCTION at runtime (`$f = "strlen";
        // $f(...)`, or call_user_func with a runtime name). Dispatch on the name
        // against the module's arity-compatible user functions.
        if ($iv->callee->type->kind === Type::KIND_STRING) {
            return $this->emitDynFnCall($iv);
        }
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
        // A `...$arr` spread into a DYNAMIC closure (concrete __closure_N lost ⇒
        // arity unknown, e.g. a `callable`/`\Closure` param): the fixed-arity
        // fill below can't apply. Route to a trampoline that switches on the
        // runtime arg count and calls the fn ptr with EXACTLY that many slots.
        $dynSpread = -1;
        foreach ($iv->args as $si => $sa) {
            if ($sa->kind === Node::KIND_SPREAD) { $dynSpread = $si; break; }
        }
        if ($dynSpread !== -1 && !$known) {
            return $out . $this->emitDynClosureSpread($struct, $iv->args, $dynSpread, $n->type);
        }
        // Running param index — diverges from the loop key once a spread has
        // expanded into multiple positional slots.
        $pi = 0;
        foreach ($iv->args as $a) {
            // Argument unpacking `$fn(...$arr)`: expand the array across the
            // closure's remaining declared params (fixed-arity), boxing each
            // scalar element per the uniform closure ABI. A DYNAMIC callee has
            // no static arity — the indirect call builds its own signature and
            // the closure struct carries no arity, so a spread can't be
            // materialized (padding an indirect call is UB). Fail loud rather
            // than emit the corrupt arg list the old no-op `visitSpread` left.
            if ($a->kind === Node::KIND_SPREAD) {
                if (!$known) {
                    throw new \RuntimeException('spread into a dynamic callable of unknown arity is unsupported');
                }
                $out .= $this->emitNode($a->operand);
                $out .= $this->coerceToPtr();
                $arr = $this->lastValue;
                $elemType = $a->operand->type->element ?? null;
                $nparams = \count($calleeParams);
                $base = $pi;
                while ($pi < $nparams) {
                    $ev = $this->ssa->allocReg();
                    $out .= '  ' . $ev . ' = call i64 @__mir_array_value_at(ptr ' . $arr
                          . ', i64 ' . (string)($pi - $base) . ")\n";
                    if ($elemType !== null && $elemType->kind !== Type::KIND_CELL
                        && $this->isCellBoxableArg($elemType)) {
                        $this->lastValue = $ev;
                        $this->lastValueType = 'i64';
                        $out .= $this->boxToCell($elemType);
                        $ev = $this->lastValue;
                    }
                    $argList .= ', i64 ' . $ev;
                    $argTypes .= ', i64';
                    $pi = $pi + 1;
                }
                continue;
            }
            $out .= $this->emitNode($a);
            $pt = $calleeParams[$pi] ?? null;
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
            $pi = $pi + 1;
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
     * `$cb(...$arr)` where `$cb` is a DYNAMIC closure of unknown arity. The
     * closure struct carries no arity and an indirect call's signature is fixed
     * per call site, so we can't emit one variable-length call. Instead switch
     * on the runtime arg count (numFixed + array length) and, for each count K,
     * emit a fixed K-arg indirect call — so when the runtime count matches the
     * closure's real arity (the correct-args case) the signature matches
     * exactly, no UB. Counts beyond MAX_DYN_SPREAD_ARITY fall through to a
     * zero result. Each scalar arg is boxed to a tagged cell (uniform closure
     * ABI), the same as the static-arity path.
     * @param Node[] $args
     */
    private function emitDynClosureSpread(string $struct, array $args, int $spreadPos, Type $retType): string
    {
        if ($spreadPos !== \count($args) - 1) {
            throw new \RuntimeException('only a single trailing spread into a dynamic closure is supported');
        }
        $maxArity = 10;
        $numFixed = $spreadPos;
        $out = '';
        $fixedRegs = [];
        for ($i = 0; $i < $numFixed; $i = $i + 1) {
            $out .= $this->emitNode($args[$i]);
            if ($this->isCellBoxableArg($args[$i]->type)) {
                $out .= $this->boxToCell($args[$i]->type);
            } else {
                $out .= $this->coerceToI64();
            }
            $fixedRegs[] = $this->lastValue;
        }
        $out .= $this->emitNode($args[$spreadPos]->operand);
        $out .= $this->coerceToPtr();
        $arr = $this->lastValue;
        $elemType = $args[$spreadPos]->operand->type->element ?? null;
        $boxElem = $elemType !== null && $elemType->kind !== Type::KIND_CELL
            && $elemType->kind !== Type::KIND_UNKNOWN
            && $this->isCellBoxableArg($elemType);
        $len = $this->ssa->allocReg();
        $out .= '  ' . $len . ' = call i64 @__mir_array_live_len(ptr ' . $arr . ")\n";
        $total = $this->ssa->allocReg();
        $out .= '  ' . $total . ' = add i64 ' . $len . ', ' . (string)$numFixed . "\n";
        $fpi = $this->ssa->allocReg();
        $out .= '  ' . $fpi . ' = load i64, ptr ' . $struct . "\n";
        $fp = $this->ssa->allocReg();
        $out .= '  ' . $fp . ' = inttoptr i64 ' . $fpi . " to ptr\n";
        $res = $this->ssa->allocReg();
        $out .= '  ' . $res . " = alloca i64\n";
        $out .= '  store i64 0, ptr ' . $res . "\n";
        $endL = $this->ssa->allocLabel('clspread.end');
        $defL = $this->ssa->allocLabel('clspread.def');
        $caseL = [];
        for ($k = $numFixed; $k <= $maxArity; $k = $k + 1) {
            $caseL[$k] = $this->ssa->allocLabel('clspread.c' . $k);
        }
        $sw = '  switch i64 ' . $total . ', label %' . $defL . " [\n";
        for ($k = $numFixed; $k <= $maxArity; $k = $k + 1) {
            $sw .= '    i64 ' . $k . ', label %' . $caseL[$k] . "\n";
        }
        $out .= $sw . "  ]\n";
        for ($k = $numFixed; $k <= $maxArity; $k = $k + 1) {
            $out .= $caseL[$k] . ":\n";
            $argList = 'ptr ' . $struct;
            $argTypes = 'ptr';
            for ($f = 0; $f < $numFixed; $f = $f + 1) {
                $argList .= ', i64 ' . $fixedRegs[$f];
                $argTypes .= ', i64';
            }
            for ($e = 0; $e < $k - $numFixed; $e = $e + 1) {
                $ev = $this->ssa->allocReg();
                $out .= '  ' . $ev . ' = call i64 @__mir_array_value_at(ptr ' . $arr
                      . ', i64 ' . (string)$e . ")\n";
                if ($boxElem) {
                    $this->lastValue = $ev;
                    $this->lastValueType = 'i64';
                    $out .= $this->boxToCell($elemType);
                    $ev = $this->lastValue;
                }
                $argList .= ', i64 ' . $ev;
                $argTypes .= ', i64';
            }
            $rk = $this->ssa->allocReg();
            $out .= '  ' . $rk . ' = call i64 (' . $argTypes . ') ' . $fp . '(' . $argList . ")\n";
            $out .= '  store i64 ' . $rk . ', ptr ' . $res . "\n";
            $out .= '  br label %' . $endL . "\n";
        }
        $out .= $defL . ":\n";
        $out .= '  br label %' . $endL . "\n";
        $out .= $endL . ":\n";
        $rres = $this->ssa->allocReg();
        $out .= '  ' . $rres . ' = load i64, ptr ' . $res . "\n";
        $this->lastValue = $rres;
        $this->lastValueType = 'i64';
        if ($this->isCellScalarParam($retType)) {
            $out .= $this->unboxCellToType($retType);
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
    /**
     * Whether a by-ref arg is a CELL being handed to a param that expects a raw
     * payload — the case that needs an unbox/re-box around the call.
     *
     * The by-VALUE path unboxes (see unboxCellArg), but a by-ref arg passes the
     * slot ADDRESS, so the callee reads the caller's NaN-boxed bits as a raw
     * pointer and faults: `$a = file($p); sort($a);` (file returns
     * `string[]|false` ⇒ a cell) dereferenced the tag.
     *
     * An erased param (KIND_UNKNOWN — what a bare `array` hint lowers to, see
     * LowerTypes) counts: erased still means a RAW array at runtime, the
     * elements being cells. Gating on KIND_CELL alone would let exactly these
     * fall through.
     * @param Type[] $ptypes
     */
    private function byRefNeedsCellUnbox(Node $a, array $ptypes, int $ai): bool
    {
        if ($a->type->kind !== Type::KIND_CELL) { return false; }
        $pt = $ptypes[$ai] ?? null;
        if ($pt === null) { return false; }
        $pk = $pt->kind;
        return $pk === Type::KIND_UNKNOWN || $pk === Type::KIND_ARRAY
            || $pk === Type::KIND_STRING;
    }

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
     * Fixed-arity spread expansion for a KNOWN callee: read the elements of the
     * runtime array `$arrReg` filling the callee's params `[$firstParam ..
     * count($ptypes))`. Each element is boxed to a tagged cell when the target
     * param is tagged/cell and the source element is a concrete scalar (the
     * same boundary rule the inline call-site spread arms use). Returns the IR
     * plus the SSA i64 regs for the produced args (caller appends them to its
     * own arg list with the right separators / `$this` offset).
     * @param Type[] $ptypes
     * @param array<int,bool> $tmask
     * @return array{0:string,1:string[]}
     */
    private function emitSpreadFill(string $arrReg, int $firstParam, array $ptypes, array $tmask, ?Type $elemType): array
    {
        $out = '';
        $regs = [];
        $n = \count($ptypes);
        for ($k = $firstParam; $k < $n; $k = $k + 1) {
            $ev = $this->ssa->allocReg();
            $out .= '  ' . $ev . ' = call i64 @__mir_array_value_at(ptr ' . $arrReg
                  . ', i64 ' . (string)($k - $firstParam) . ")\n";
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
            $regs[] = $ev;
        }
        return [$out, $regs];
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
        $camask = $this->sigs->cellArgParams[$c->function] ?? [];
        $ptypes = $this->sigs->paramTypes[$c->function] ?? [];
        $ai = 0;
        // Fresh string-temp arg carriers freed after the call: a borrow the
        // callee retains if it keeps it (the +1 convention), so the caller's
        // transient is dead once the call returns.
        $argTemps = [];
        // Cell lvalues unboxed into a scratch slot for a raw-payload by-ref
        // param, re-boxed into the caller's slot after the call. Parallel.
        $reboxSlots = [];
        $reboxTmps = [];
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
            if (($mask[$ai] ?? false) && $this->isByRefAddressable($a)
                && $this->byRefNeedsCellUnbox($a, $ptypes, $ai)
            ) {
                // Cell lvalue → raw-payload by-ref param: hand the callee a
                // scratch slot holding the UNTAGGED payload, then re-box what it
                // left back into the caller's slot. Passing the cell slot
                // directly makes the callee deref the tag bits.
                $out .= $this->byRefAddrOf($a);
                $slotAddr = $this->lastValue;
                $sp = $this->ssa->allocReg();
                $out .= '  ' . $sp . ' = inttoptr i64 ' . $slotAddr . " to ptr\n";
                $cv = $this->ssa->allocReg();
                $out .= '  ' . $cv . ' = load i64, ptr ' . $sp . "\n";
                $raw = $this->ssa->allocReg();
                $out .= '  ' . $raw . ' = and i64 ' . $cv . ", 281474976710655\n";
                $tmp = $this->ssa->allocReg();
                $out .= '  ' . $tmp . " = alloca i64\n";
                $out .= '  store i64 ' . $raw . ', ptr ' . $tmp . "\n";
                $taddr = $this->ssa->allocReg();
                $out .= '  ' . $taddr . ' = ptrtoint ptr ' . $tmp . " to i64\n";
                $argList .= 'i64 ' . $taddr;
                $reboxSlots[] = $slotAddr;
                $reboxTmps[] = $tmp;
            } elseif (($mask[$ai] ?? false) && $this->isByRefAddressable($a)) {
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
            } elseif (($camask[$ai] ?? false)
                && $a->type->isArray() && $a->type->element !== null
                && $a->type->element->kind !== Type::KIND_CELL
                && $a->type->element->kind !== Type::KIND_UNKNOWN) {
                // A `#[CellArg]` param (element-CONSUMING, e.g. fputcsv's $fields)
                // fed a concrete-element array: rebuild it with each element boxed
                // so the once-compiled stdlib callee — which reads element VALUES
                // as tagged cells — sees a self-describing array instead of raw
                // slots it would decode as garbage. Gated by the sig flag so
                // element-PRESERVING passthrough fns (array_merge/combine) keep
                // the raw repr. cell/unknown elements are already tag-safe.
                $out .= $this->emitNode($a);
                $out .= $this->emitCellifyArrayRaw($a->type->element);
                $out .= $this->coerceToI64();
                $argList .= 'i64 ' . $this->lastValue;
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
        // Re-box each unboxed by-ref arg. The value is READ BACK, not assumed
        // unchanged: sort()/usort() reorder in place but may hand back a
        // different buffer. Boxed as vec[cell] so boxToCell takes the flat
        // box_array path — a concrete element type would make it REBUILD the
        // array, which would silently break the by-ref aliasing the caller
        // expects.
        $bi = 0;
        foreach ($reboxTmps as $rtmp) {
            $rv = $this->ssa->allocReg();
            $out .= '  ' . $rv . ' = load i64, ptr ' . $rtmp . "\n";
            $this->lastValue = $rv;
            $this->lastValueType = 'i64';
            $out .= $this->boxToCell(Type::vec(Type::cell()));
            $rsp = $this->ssa->allocReg();
            $out .= '  ' . $rsp . ' = inttoptr i64 ' . $reboxSlots[$bi] . " to ptr\n";
            $out .= '  store i64 ' . $this->lastValue . ', ptr ' . $rsp . "\n";
            $bi = $bi + 1;
        }
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
