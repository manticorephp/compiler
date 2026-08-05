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
        // `#[Ffi\Library('name')]` → a link requirement. Collected at the
        // WRAPPER, so the set is exactly what this module emitted rather than
        // everything the source happened to declare. 'c' is implicit.
        if ($fn->ffiLibrary !== '' && $fn->ffiLibrary !== 'c') {
            $this->ffiLibs[$fn->ffiLibrary] = true;
        }
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
            } elseif ($ct === 'i1' || $ct === 'i8' || $ct === 'i16' || $ct === 'i32') {
                // Narrow the i64 carrier to the C parameter's real width. The
                // upper bits were never the callee's to read — AAPCS64 and SysV
                // both leave them unspecified for a narrow argument — but a
                // VARIADIC arg is read off the stack at its natural size, so the
                // width has to be right there.
                $r = $this->ssa->allocReg();
                $out .= '  ' . $r . ' = trunc i64 ' . $src . ' to ' . $ct . "\n";
                $cargs[] = $ct . ' ' . $r;
            } elseif ($ct === 'float') {
                // C `float` is 32-bit; the carrier holds a double's bit pattern.
                $rd = $this->ssa->allocReg();
                $r = $this->ssa->allocReg();
                $out .= '  ' . $rd . ' = bitcast i64 ' . $src . " to double\n";
                $out .= '  ' . $r . ' = fptrunc double ' . $rd . " to float\n";
                $cargs[] = 'float ' . $r;
            } else {
                $cargs[] = 'i64 ' . $src;
            }
            $idx = $idx + 1;
        }
        // A VARIADIC C function (`#[Ffi\Variadic($fixed)]`): declare and CALL
        // with an explicit variadic function type — `ret (t0, …, ...)` — so the
        // backend applies the target's variadic ABI. Darwin arm64 passes varargs
        // on the STACK, so a plain fixed-arity call hands the callee register
        // garbage where it does `va_arg`. `$variadicFixed` is the count of NAMED
        // params (those before the `...`), or -1 for an ordinary symbol.
        //
        // The two locals below are the call-type token and the declare's param
        // list. Both branches assign PLAIN STRINGS, never a maybe-null local — a
        // `?string` here once read as garbage under the self-built compiler and
        // the wrapper emitted `call <ptr> @fclose`. Same rule as the carrier
        // field itself; {@see \Compile\Mir\FunctionDef}.
        $variadicFixed = $fn->ffiVariadicFixed;
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
            // `#[Ffi\Weak]`: a symbol that may be absent on this target (e.g.
            // epoll_* on macOS) binds to null via extern_weak; the call is
            // guarded by a runtime OS branch so it never fires where absent.
            $weak = $fn->ffiWeak ? 'extern_weak ' : '';
            if ($fn->ffiWeak) { $this->weakSyms[$cSym] = true; }
            $line = 'declare ' . $weak . $ret . ' @' . $cSym . '(' . $declParams . ')';
            // TWO bindings of one C symbol must agree about its C signature.
            // libcExtra is keyed by symbol, so without this check the second
            // binding's declare is simply dropped and whichever wrapper was
            // emitted first decides what every call site is typed against —
            // silently, and in emission order, which is not a property anyone
            // reasons about. The mismatch is the SSL_read bug's shape: one
            // binding says the callee returns i32, the other reads x0 as i64
            // and gets whatever the upper half happened to hold.
            $prev = $this->libcExtra[$cSym] ?? '';
            if ($prev !== '' && $prev !== $line) {
                throw new \RuntimeException(
                    'conflicting FFI declarations for C symbol "' . $cSym . '": '
                    . $fn->name . ' declares `' . $line . '` but '
                    . ($this->ffiDeclOwner[$cSym] ?? '(a builtin)')
                    . ' already declared `' . $prev
                    . '`. Every binding of one C symbol must agree — annotate the'
                    . ' parameters and return with #[Ffi\\CType] so they do.');
            }
            $this->libcExtra[$cSym] = $line;
            $this->ffiDeclOwner[$cSym] = $fn->name;
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
            } elseif ($ret === 'float') {
                // C `float` is 32-bit; the PHP carrier is a double bit-cast into
                // i64. Widen first, then reinterpret.
                $rd = $this->ssa->allocReg();
                $ri = $this->ssa->allocReg();
                $out .= '  ' . $rd . ' = fpext float ' . $r . " to double\n";
                $out .= '  ' . $ri . ' = bitcast double ' . $rd . " to i64\n";
                $out .= '  ret i64 ' . $ri . "\n";
            } elseif ($ret === 'i32' || $ret === 'i16' || $ret === 'i8') {
                // A NARROW C integer return must be extended into the i64
                // carrier, and the direction is the C type's signedness: the
                // callee wrote only the low half, so a signed -1 read as i64
                // would answer 4294967295. That is exactly how SSL_read's
                // WANT_READ became a 4 GB memmove length.
                // {@see LowerFromAst::ffiCTypeToken}
                $ext = $fn->ffiRetUnsigned ? 'zext' : 'sext';
                $ri = $this->ssa->allocReg();
                $out .= '  ' . $ri . ' = ' . $ext . ' ' . $ret . ' ' . $r . " to i64\n";
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
        //
        // The env carries a LIFETIME HEADER at negative offsets — the string
        // header's shape, exactly as a Generator frame does, so no capture
        // offset moves and a misrouted generic release still frees the right
        // base: `[MAGIC@-32, retain@-24, drop@-16, rc@-8]`. Without it neither
        // the env nor the +1 this function takes on every captured
        // string/array/object was EVER freed — 16 B per closure plus the whole
        // captured value, at every evaluation of the literal (measured: 80 B an
        // iteration for a captured string, 112 for an array).
        $fnName = '__closure_' . (string)$cl->id;
        $hdr = \Compile\MemoryAbi::STRING_HEADER_SIZE;
        $sz = $hdr + 8 * (1 + $cnt);
        $base = $this->ssa->allocReg();
        $out = '  ' . $base . ' = call ptr @__mir_alloc(i64 ' . (string)$sz . ")\n";
        $buf = $this->ssa->allocReg();
        $out .= '  ' . $buf . ' = getelementptr inbounds i8, ptr ' . $base
              . ', i64 ' . (string)$hdr . "\n";
        $this->rt->needsClosureRc = true;
        $out .= $this->closureHdrStore($buf, \Compile\MemoryAbi::STRING_HASH_OFFSET,
            (string)\Compile\MemoryAbi::CLOSURE_TAG_MAGIC);
        // The per-closure retain/drop pair; both null when the closure owns
        // nothing through its env, which keeps release a plain free.
        $dropV = '0';
        $retV = '0';
        $flavors = $this->closureCaptureFlavors($cl);
        if ($this->closureOwnsCaptures($flavors)) {
            $this->closureDrops[$fnName] = $flavors;
            $dropReg = $this->ssa->allocReg();
            $out .= '  ' . $dropReg . ' = ptrtoint ptr @manticore_'
                  . $this->mangle($fnName . '__mc_drop') . " to i64\n";
            $dropV = $dropReg;
            $retReg = $this->ssa->allocReg();
            $out .= '  ' . $retReg . ' = ptrtoint ptr @manticore_'
                  . $this->mangle($fnName . '__mc_retain') . " to i64\n";
            $retV = $retReg;
        }
        $out .= $this->closureHdrStore($buf, \Compile\MemoryAbi::CLOSURE_RETAIN_OFFSET, $retV);
        $out .= $this->closureHdrStore($buf, \Compile\MemoryAbi::CLOSURE_DROP_OFFSET, $dropV);
        $out .= $this->closureHdrStore($buf, \Compile\MemoryAbi::STRING_RC_OFFSET, '1');
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
                // The closure co-owns a reference to each captured rc value. A
                // CELL/UNKNOWN capture — a resource/object read off a `mixed` or
                // bare array, or a `T|false` return — needs a TAG-checked retain:
                // rcRetainByType only handles concrete obj/array/string and would
                // no-op it, leaving the closure a dangling pointer once the
                // enclosing scope frees the value (use-after-free — surfaced by a
                // fiber that captures a socket and runs after the accept loop
                // reassigned the variable).
                $ck = $c->type->kind;
                if ($ck === Type::KIND_CELL || $ck === Type::KIND_UNKNOWN) {
                    $this->rt->needsRc = true;
                    $this->rt->needsStrRc = true;
                    $out .= '  call void @__mir_cell_retain(i64 ' . $capV . ")\n";
                    // …and the other half: cell_retain answers on the cell tag
                    // and does NOTHING for an untagged word, so a container on
                    // an erased slot — a bare `array` hint lowers to unknown —
                    // was captured without being co-owned. An argument with no
                    // other owner (an array LITERAL passed straight into the
                    // call) was then freed at the caller's scope exit and the
                    // closure read freed memory. The two are disjoint.
                    $out .= $this->rawContainerRetainIr($capV);
                } else {
                    $out .= $this->rcRetainByType($c, $capV, null, 1);
                }
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

    /** Register set by {@see closureHdrLoad} alongside its returned value. */
    private string $closureHdrLoadOut = '';

    /** One header word of a closure env, read through its VALUE pointer; the
     *  IR lands in {@see $closureHdrLoadOut}. */
    private function closureHdrLoad(string $env, int $offset): string
    {
        $p = $this->ssa->allocReg();
        $out  = '  ' . $p . ' = getelementptr inbounds i8, ptr ' . $env
              . ', i64 ' . (string)$offset . "\n";
        $v = $this->ssa->allocReg();
        $out .= '  ' . $v . ' = load i64, ptr ' . $p . "\n";
        $this->closureHdrLoadOut = $out;
        return $v;
    }

    /** One header word of a closure env, written through its VALUE pointer. */
    private function closureHdrStore(string $env, int $offset, string $value): string
    {
        $p = $this->ssa->allocReg();
        $out  = '  ' . $p . ' = getelementptr inbounds i8, ptr ' . $env
              . ', i64 ' . (string)$offset . "\n";
        $out .= '  store i64 ' . $value . ', ptr ' . $p . "\n";
        return $out;
    }

    /**
     * The rc flavor of each capture, in slot order, read from the LITERAL's
     * capture expressions — the only faithful description of what the env
     * holds. ⚠ NOT the closure body's capture params: the uniform closure ABI
     * types a scalar param as a CELL and the body unboxes it, so reading the
     * flavors there had the drop call `__mir_cell_drop` on a raw string pointer
     * — a silent no-op, and the captured value went on leaking while the env
     * itself was correctly freed.
     *
     * It is the exact mirror of the retain {@see emitClosure} emits, which is
     * what makes the generated pair balanced by construction:
     *   - a BY-REF capture packs the ADDRESS of the enclosing slot, never a
     *     value ⇒ skipped;
     *   - CELL / UNKNOWN was co-owned by `__mir_cell_retain` ⇒ `__mir_cell_drop`
     *     (the `rawContainerRetainIr` half has no mirror — that stays a leak,
     *     never an over-release);
     *   - everything else goes through `rcRetainByType`, which co-owns exactly
     *     string / array / obj / closure, so those and only those are dropped.
     * An owned producer transfers its +1 instead of being retained; either way
     * the env holds exactly ONE reference, so one drop is right.
     *
     * @return string[] per-capture flavor, '' = nothing to own
     */
    private function closureCaptureFlavors(Closure_ $cl): array
    {
        $flavors = [];
        $i = 0;
        foreach ($cl->captures as $c) {
            $ref = ($cl->captureByRef[$i] ?? false) && $c->kind === Node::KIND_LOAD_LOCAL;
            $i = $i + 1;
            if ($ref) { $flavors[] = ''; continue; }
            $t = $c->type;
            $k = $t->kind;
            if ($k === Type::KIND_CELL || $k === Type::KIND_UNKNOWN) { $flavors[] = 'cell'; continue; }
            if ($k === Type::KIND_STRING) { $flavors[] = 'str'; continue; }
            if ($t->isArray()) { $flavors[] = 'arr'; continue; }
            if ($k === Type::KIND_CLOSURE) { $flavors[] = 'closure'; continue; }
            if ($k === Type::KIND_OBJ) {
                $cls = $t->class ?? '';
                if ($cls === 'Ffi\\Ptr' || $cls === 'Generator') { $flavors[] = ''; continue; }
                if ($this->isClosureClass($cls)) { $flavors[] = 'closure'; continue; }
                if ($this->isEnumClass($cls)) { $flavors[] = ''; continue; }
                if ($cls !== '' && isset($this->classes[$cls]) && $this->classes[$cls]->isStruct) {
                    $flavors[] = '';
                    continue;
                }
                $flavors[] = 'obj';
                continue;
            }
            $flavors[] = '';
        }
        return $flavors;
    }

    /** @param string[] $flavors  Does this env own anything? */
    private function closureOwnsCaptures(array $flavors): bool
    {
        foreach ($flavors as $f) {
            if ($f !== '') { return true; }
        }
        return false;
    }

    /**
     * `@manticore___closure_N__mc_drop` / `__mc_retain` for every capturing
     * closure in this module, emitted after the function bodies. `internal`,
     * like the closure bodies: the name is a per-module counter and the address
     * travels inside the env, never through the linker.
     *
     * The RETAIN twin exists for `Closure::bind`/`bindTo`/`call`, which copy an
     * env slot-for-slot: without it the copy would alias captures it does not
     * own and the original's release would free them underneath it.
     */
    private function emitClosureDropFns(): string
    {
        $out = '';
        foreach ($this->closureDrops as $fnName => $flavors) {
            $out .= $this->closureRcFn($fnName, $flavors, false);
            $out .= $this->closureRcFn($fnName, $flavors, true);
        }
        return $out;
    }

    /** @param string[] $flavors */
    private function closureRcFn(string $fnName, array $flavors, bool $retain): string
    {
        $suffix = $retain ? '__mc_retain' : '__mc_drop';
        $out = 'define internal void @manticore_' . $this->mangle($fnName . $suffix)
             . "(ptr %env) {\nentry:\n";
        $i = 0;
        foreach ($flavors as $flavor) {
            $i = $i + 1;
            if ($flavor === '') { continue; }
            $gep = $this->ssa->allocReg();
            $out .= '  ' . $gep . ' = getelementptr inbounds i64, ptr %env, i64 '
                  . (string)$i . "\n";
            $v = $this->ssa->allocReg();
            $out .= '  ' . $v . ' = load i64, ptr ' . $gep . "\n";
            if ($flavor === 'cell') {
                $out .= '  call void @' . ($retain ? '__mir_cell_retain' : '__mir_cell_drop')
                      . '(i64 ' . $v . ")\n";
                continue;
            }
            $p = $this->ssa->allocReg();
            $out .= '  ' . $p . ' = inttoptr i64 ' . $v . " to ptr\n";
            if ($flavor === 'str') {
                $fn = $retain ? '__mir_rc_retain_str' : '__mir_rc_release_str';
            } elseif ($flavor === 'arr') {
                $fn = $retain ? '__mir_array_retain' : '__mir_array_release';
            } elseif ($flavor === 'closure') {
                $fn = $retain ? '__mir_closure_retain' : '__mir_closure_release';
            } else {
                $fn = $retain ? '__mir_rc_retain' : '__mir_rc_release';
            }
            $out .= '  call void @' . $fn . '(ptr ' . $p . ")\n";
        }
        $out .= "  ret void\n}\n";
        return $out;
    }

    /** `$closure(args)` → load captures from the struct, call __closure_N. */
    /** `$f(args)` / call_user_func($f, args) with `$f` a runtime function-name
     *  string. strcmp the name against each arity-compatible FREE user function
     *  and reuse the normal Call path for the match, boxing the result to a
     *  cell; an unmatched name yields null. Method keys (`Class__method`) and
     *  `__main` are excluded (the `__` marker). One arm runs, so re-emitting
     *  args per arm evaluates them once at runtime. */
    private function emitDynFnCall(Invoke_ $iv, string $keyPtr = ''): string
    {
        $this->rt->needsStrcmp = true;
        // A caller that already materialized the name (the erased-callee tag
        // dispatch) passes it in — re-emitting the callee there would evaluate
        // it twice and, worse, hand this chain the still-boxed word.
        $out = '';
        $keyP = $keyPtr;
        if ($keyPtr === '') {
            $out = $this->emitNode($iv->callee);
            $out .= $this->coerceToPtr();
            $keyP = $this->lastValue;
        }
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
            // Arity alone is not enough to make a candidate emittable. A FLOAT is
            // the one kind whose LLVM carrier is `double` rather than i64/ptr, so
            // pairing a float argument with a pointer parameter (or the reverse)
            // produces IR that does not even verify — `%r = bitcast i64 %x to
            // double` followed by `icmp eq ptr %r, null`. Such a pairing can never
            // be the runtime target anyway (php would TypeError), so drop the arm
            // rather than emit it: `$hf($sock, $timeout)` in __mc_dns_exchange
            // matched `explode(string, string)` on arity and killed the cold seed.
            if (!$hasSpread && !$this->dynArmTypesEmittable($ptypes, $iv->args)) {
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

    /**
     * Whether a dynamic-name dispatch arm for a candidate with `$ptypes` can be
     * emitted at all against these argument nodes. Only the float-vs-pointer
     * pairing is rejected: every other kind rides an i64 carrier, so the existing
     * coercions produce verifiable IR even when the pairing is nonsense (the arm
     * is unreachable at runtime either way). A CELL / UNKNOWN on either side says
     * nothing statically and always stays.
     *
     * @param Type[] $ptypes
     * @param Node[] $args
     */
    private function dynArmTypesEmittable(array $ptypes, array $args): bool
    {
        foreach ($args as $i => $a) {
            $pt = $ptypes[$i] ?? null;
            if ($pt === null) { continue; }
            $ak = $a->type->kind;
            $pk = $pt->kind;
            if ($ak === Type::KIND_FLOAT && $this->isPtrCarrierKind($pk)) { return false; }
            if ($pk === Type::KIND_FLOAT && $this->isPtrCarrierKind($ak)) { return false; }
        }
        return true;
    }

    /** A kind whose LLVM carrier is a pointer rather than a plain i64/double. */
    private function isPtrCarrierKind(string $kind): bool
    {
        return $kind === Type::KIND_STRING || $kind === Type::KIND_ARRAY
            || $kind === Type::KIND_OBJ || $kind === Type::KIND_CLOSURE;
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
        // An ERASED callee — a `mixed`/`callable` param, an element read out of a
        // vec[cell], a static-prop slot. The value is NaN-boxed, so the struct
        // path's bare inttoptr called through the TAG BITS (segfault), and a
        // function-NAME string held in the same slot never reached the by-name
        // dispatch at all. Decide on the runtime tag instead.
        $ck = $iv->callee->type->kind;
        if ($ck === Type::KIND_CELL || $ck === Type::KIND_UNKNOWN) {
            return $this->emitErasedInvoke($n);
        }
        // The closure struct is the env: the __closure fn unpacks its own
        // captures from it (slot 1+), so the call passes only `env + args`.
        $out = $this->emitNode($iv->callee);
        // A `mixed`/`cell` callee (e.g. `AsyncHook::readable(): mixed` returning
        // a closure) still carries NaN-box TAG BITS in its i64 slot — those must
        // be masked off before we treat the value as a struct pointer. Missing
        // this mask reads the fn ptr from a tagged address → SIGSEGV on the very
        // first indirect call (the "transparent I/O" AsyncHook path). Concrete
        // `Closure`/object-typed callees are already stored as raw pointers, so
        // they don't need the mask; only untype-erased slots do.
        $ck = $iv->callee->type->kind;
        if ($ck === Type::KIND_CELL || $ck === Type::KIND_UNKNOWN) {
            $out .= $this->coerceToI64();
            $r = $this->ssa->allocReg();
            $out .= '  ' . $r . ' = and i64 ' . $this->lastValue . ", 281474976710655\n";
            $this->lastValue = $r;
            $this->lastValueType = 'i64';
        }
        $out .= $this->coerceToPtr();
        return $out . $this->emitClosureStructInvoke($n, $this->lastValue);
    }

    /**
     * `$cb(args)` with `$cb` an erased (cell / unknown) slot. One evaluation of
     * the callee, then a branch on its cell tag: a STRING cell (tag 4) is a
     * function name → the by-name dispatch; anything else is a closure struct
     * whose payload is masked out of the box. Both arms leave a boxed cell, so
     * the merged result matches the invoke's inferred cell type.
     */
    private function emitErasedInvoke(Invoke_ $n): string
    {
        $out = $this->emitNode($n->callee);
        $out .= $this->coerceToI64();
        $raw = $this->lastValue;
        $out .= $this->cellTagIr($raw);
        $isStr = $this->ssa->allocReg();
        $out .= '  ' . $isStr . ' = icmp eq i64 ' . $this->cellTagReg . ", 4\n";
        $res = $this->ssa->allocReg();
        $out .= '  ' . $res . " = alloca i64\n";
        $out .= '  store i64 0, ptr ' . $res . "\n";
        $nameL = $this->ssa->allocLabel('erinv.name');
        $closL = $this->ssa->allocLabel('erinv.clos');
        $endL = $this->ssa->allocLabel('erinv.end');
        $out .= '  br i1 ' . $isStr . ', label %' . $nameL . ', label %' . $closL . "\n";

        $out .= $nameL . ":\n";
        $keyM = $this->ssa->allocReg();
        $out .= '  ' . $keyM . ' = and i64 ' . $raw . ", 281474976710655\n";
        $keyP = $this->ssa->allocReg();
        $out .= '  ' . $keyP . ' = inttoptr i64 ' . $keyM . " to ptr\n";
        $out .= $this->emitDynFnCall($n, $keyP);
        $out .= '  store i64 ' . $this->lastValue . ', ptr ' . $res . "\n";
        $out .= '  br label %' . $endL . "\n";

        $out .= $closL . ":\n";
        $stM = $this->ssa->allocReg();
        $out .= '  ' . $stM . ' = and i64 ' . $raw . ", 281474976710655\n";
        $struct = $this->ssa->allocReg();
        $out .= '  ' . $struct . ' = inttoptr i64 ' . $stM . " to ptr\n";
        // No boxing here: the uniform closure ABI ALREADY returns a scalar as a
        // tagged cell, so re-boxing turned a string cell into an int cell whose
        // payload was then dereferenced as a char* (segfault). The join unboxes
        // once, exactly as the direct closure path does.
        $out .= $this->emitClosureStructInvoke($n, $struct, false);
        $out .= '  store i64 ' . $this->lastValue . ', ptr ' . $res . "\n";
        $out .= '  br label %' . $endL . "\n";

        $out .= $endL . ":\n";
        $loaded = $this->ssa->allocReg();
        $out .= '  ' . $loaded . ' = load i64, ptr ' . $res . "\n";
        $this->lastValue = $loaded;
        $this->lastValueType = 'i64';
        if ($this->isCellScalarParam($n->type)) {
            $out .= $this->unboxCellToType($n->type);
        }
        return $out;
    }

    /**
     * The closure-struct call itself, given the already-materialized env `ptr`.
     * Split out of {@see emitInvoke} so an erased callee can reach it with a
     * payload masked out of a NaN-boxed cell. `$unboxResult` is false when the
     * caller merges arms and unboxes once at the join.
     */
    private function emitClosureStructInvoke(Invoke_ $n, string $struct, bool $unboxResult = true): string
    {
        $iv = $n;
        $out = '';
        $fn = $iv->callee->type->class ?? '';
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
        // A closure's params are PREFIXED by its captures (EmitLlvmModule emits
        // `ptr %env` plus params[capCnt..]), and every sig mask — paramTypes,
        // refParams — is index-parallel to that FULL list. The argument at
        // position $pi is therefore params[$capCnt + $pi]; indexing from 0 read
        // a CAPTURE's type instead, so every boxing decision below was made
        // against the wrong param for any closure that captures anything.
        $capCnt = $known ? ($this->closureCaptures[$fn] ?? 0) : 0;
        $calleeParams = $known ? ($this->sigs->paramTypes[$fn] ?? []) : [];
        $calleeRefs = $known ? ($this->sigs->refParams[$fn] ?? []) : [];
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
                // Declared ARGUMENT arity — the captures are not call slots.
                $nparams = \count($calleeParams) - $capCnt;
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
            // A BY-REF callback param (`array_walk($a, function (&$v) {...})`)
            // takes the ADDRESS of the caller's slot, exactly like the named-call
            // path below: the closure body stores `%arg.<name>` raw and treats it
            // as a ref-local, so handing it a boxed VALUE made it dereference
            // NaN-boxed tag bits. Without this the callee's writes vanished (a
            // silently dropped mutation) or crashed.
            if (($calleeRefs[$capCnt + $pi] ?? false)) {
                if ($this->isByRefAddressable($a)) {
                    $out .= $this->byRefAddrOf($a);
                } else {
                    // Not an lvalue — back it with a throwaway slot so the
                    // callee's write lands somewhere (PHP discards it).
                    $tmp = $this->ssa->allocReg();
                    $out .= '  ' . $tmp . " = alloca i64\n";
                    $out .= $this->emitNode($a);
                    $out .= $this->coerceToI64();
                    $out .= '  store i64 ' . $this->lastValue . ', ptr ' . $tmp . "\n";
                    $addr = $this->ssa->allocReg();
                    $out .= '  ' . $addr . ' = ptrtoint ptr ' . $tmp . " to i64\n";
                    $this->lastValue = $addr;
                }
                $argList .= ', i64 ' . $this->lastValue;
                $argTypes .= ', i64';
                $pi = $pi + 1;
                continue;
            }
            $out .= $this->emitNode($a);
            $pt = $calleeParams[$capCnt + $pi] ?? null;
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
        if ($unboxResult && $this->isCellScalarParam($n->type)) {
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

    /**
     * The `#[\NoDiscard]` warning for a call whose result is thrown away, or ''.
     *
     * `(void) f();` and `$_ = f();` both stay quiet — the first via the
     * `voidCast` marker, the second because an assignment is a StoreLocal and
     * never reaches this loop's call arms. `if (f()) {}` is a condition, not a
     * block statement, so it is a USE. All three match php.
     */
    private function emitNoDiscardWarn(Node $s): string
    {
        $k = $s->kind;
        $msg = '';
        if ($k === Node::KIND_CALL) {
            if ($this->callIsVoidCast($s)) { return ''; }
            $msg = $this->noDiscardFns[$this->callFunction($s)] ?? '';
        } elseif ($k === Node::KIND_METHOD_CALL) {
            if ($this->methodCallIsVoidCast($s)) { return ''; }
            $msg = $this->noDiscardMethodMsg($this->staticClassOf($this->methodCallObject($s)),
                $this->methodCallMethod($s));
        } elseif ($k === Node::KIND_STATIC_CALL) {
            if ($this->staticCallIsVoidCast($s)) { return ''; }
            $msg = $this->noDiscardMethodMsg(\ltrim($this->staticCallClass($s), '\\'),
                $this->staticCallMethod($s));
        } else {
            return '';
        }
        if ($msg === '') { return ''; }
        return $this->emitDiagnosticLine('Warning', $msg, $s->line);
    }

    /** Keyed by the DECLARING class — an ABSTRACT or interface declaration never
     *  registers one, so it does not propagate to the concrete implementation. */
    private function noDiscardMethodMsg(string $class, string $method): string
    {
        if ($class === '') { return ''; }
        $decl = $this->resolveMethodClass($class, $method);
        if ($decl === '') { $decl = $class; }
        return $this->noDiscardMethods[$decl . '::' . $method] ?? '';
    }

    /** Subclass-typed reads of the call nodes (T5: a base-typed read resolves
     *  by OFFSET and would pick the wrong slot). */
    private function callIsVoidCast(Call $n): bool { return $n->voidCast; }
    private function callFunction(Call $n): string { return $n->function; }
    private function methodCallIsVoidCast(MethodCall_ $n): bool { return $n->voidCast; }
    private function methodCallObject(MethodCall_ $n): Node { return $n->object; }
    private function methodCallMethod(MethodCall_ $n): string { return $n->method; }
    private function staticCallIsVoidCast(StaticCall_ $n): bool { return $n->voidCast; }
    private function staticCallClass(StaticCall_ $n): string { return $n->class; }
    private function staticCallMethod(StaticCall_ $n): string { return $n->method; }

    private function emitDiscardedCallRelease(Node $s): string
    {
        $k = $s->kind;
        // A conditional in STATEMENT position (`$c ? f() : $s;`) now owns a +1
        // from whichever arm ran, so the discarded value must be dropped.
        if ($this->condOwnsResult($s)) {
            $cf = $this->condFlavor($s->type);
            return $cf === '' ? '' : $this->rcReleaseReg($this->lastValue, $cf);
        }
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
     * A spread supplies exactly `count($arr)` arguments, so every DEFAULTED
     * param the array does not reach takes its default — `$fnKey` names the
     * callee whose defaults those are. The length is a run-time property, so
     * each defaulted param selects between its element and its default;
     * reading element `k` unconditionally handed `__construct(string $t = '-')`
     * a word from past the end and the callee dereferenced it (SIGSEGV on
     * `$stmt->fetchAll(PDO::FETCH_CLASS, 'Row', [])`).
     *
     * @param Type[] $ptypes
     * @param array<int,bool> $tmask
     * @return array{0:string,1:string[]}
     */
    private function emitSpreadFill(string $arrReg, int $firstParam, array $ptypes,
                                    array $tmask, ?Type $elemType, string $fnKey = ''): array
    {
        $out = '';
        $regs = [];
        $n = \count($ptypes);
        $pdefs = $fnKey !== '' ? ($this->sigs->paramDefaults[$fnKey] ?? []) : [];
        $cnt = '';
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
            } elseif (!$needBox && $pt !== null
                && ($elemType === null
                    || $elemType->kind === Type::KIND_CELL
                    || $elemType->kind === Type::KIND_UNKNOWN)) {
                // The pack's element repr is not statically known — a mixed
                // literal (`[...['C', 5]]`) stores CELLS — and a tagged word
                // reaching a `string` param is dereferenced as a char*.
                // Unbox by the param's declared type; the helper is tag-aware,
                // so an already-raw element passes through unchanged.
                $this->lastValue = $ev;
                $this->lastValueType = 'i64';
                $out .= $this->unboxCellToType($pt);
                $out .= $this->coerceToI64();
                $ev = $this->lastValue;
            }
            $def = $pdefs[$k] ?? null;
            if ($def !== null) {
                if ($cnt === '') {
                    $out .= $this->arrayCountFromPtrIr($arrReg);
                    $cnt = $this->lastValue;
                }
                $out .= $this->emitNode($def);
                $out .= $this->coerceToI64();
                $dv = $this->lastValue;
                $has = $this->ssa->allocReg();
                $out .= '  ' . $has . ' = icmp ugt i64 ' . $cnt . ', '
                      . (string)($k - $firstParam) . "\n";
                $sel = $this->ssa->allocReg();
                $out .= '  ' . $sel . ' = select i1 ' . $has . ', i64 ' . $ev
                      . ', i64 ' . $dv . "\n";
                $ev = $sel;
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

    /**
     * One `#[\Deprecated]` / `#[\NoDiscard]` diagnostic, byte-identical to what
     * php's CLI prints with display_errors=STDOUT and html_errors=Off:
     *
     *     "\n<Level>: <body> in <file> on line <N>\n"
     *
     * Message, file and line are all compile-time constants, so the whole line
     * is ONE interned literal and one funnel call — no runtime formatting, and
     * it dead-strips with the call if the call goes away.
     *
     * Through the output funnel, like `echo`, for two reasons: it interleaves
     * correctly with program output (a `dprintf(2, …)` looks right in isolation
     * and lands in the wrong place the moment a program mixes the two), and
     * `ob_start()` captures it — which is what php does with display_errors set
     * to STDOUT.
     */
    private function emitDiagnosticLine(string $level, string $body, int $line): string
    {
        $text = "\n" . $level . ': ' . $body . ' in ' . $this->sourceFile
              . ' on line ' . (string)$line . "\n";
        return $this->emitOutLit($text);
    }

    /** The `#[\Deprecated]` line for a free function call, or ''. */
    private function deprecatedFnDiag(Call $n): string
    {
        $msg = $this->deprecatedFns[$n->function] ?? '';
        if ($msg === '') { return ''; }
        return $this->emitDiagnosticLine('Deprecated', $msg, $n->line);
    }

    private function emitCall(Call $n): string
    {
        $c = $n;
        $b = $this->emitBuiltin($c);
        if ($b !== null) { return $b; }
        $out = $this->deprecatedFnDiag($c);
        $argList = '';
        $first = true;
        $mask = $this->sigs->refParams[$c->function] ?? [];
        $tmask = $this->sigs->taggedParams[$c->function] ?? [];
        $camask = $this->sigs->cellArgParams[$c->function] ?? [];
        $ahmask = $this->sigs->arrayHintedParams[$c->function] ?? [];
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
                [$sir, $sregs] = $this->emitSpreadFill($arr, $ai, $ptypes, $tmask,
                                                       $elemType, $c->function);
                $out .= $sir;
                foreach ($sregs as $rg) {
                    if (!$first) { $argList .= ', '; }
                    $first = false;
                    $argList .= 'i64 ' . $rg;
                }
                $ai = \count($ptypes);
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
                // No post-call temp release is registered on this arm (only the
                // raw `else` below feeds $rcArgRegs), so a cellified fresh temp
                // is freed by the rebuild itself or not at all.
                $out .= $this->boxToCell($a->type, $a);
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
                $out .= $this->unboxCellArg($a, $ptypes, $ai, $ahmask);
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
        // assembles (link-stubbed by tools/link_stubs.sh).
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
