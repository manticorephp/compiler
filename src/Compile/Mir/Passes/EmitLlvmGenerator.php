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
 * Generators: the resume-function state machine, yields, and the frame words.
 * The frame's live registers are {@see \Compile\Mir\GeneratorContext}.
 *
 * A trait on the one {@see EmitLlvm} host — the split is by concern, so a reader
 * opens the file for the thing they are looking at instead of scrolling one
 * 8k-line class. State stays on the host and its collaborators.
 */
trait EmitLlvmGenerator
{
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
        $this->rt->needsStrRc = true;
        $strHdr = \Compile\MemoryAbi::STRING_HEADER_SIZE;
        $base = $this->ssa->allocReg();
        $out .= '  ' . $base . ' = call ptr @__mir_alloc(i64 ' . (string)($frameSize + $strHdr) . ")\n";
        $fr = $this->ssa->allocReg();
        $out .= '  ' . $fr . ' = getelementptr inbounds i8, ptr ' . $base . ", i64 " . (string)$strHdr . "\n";
        $out .= $this->genStoreAt($fr, -24, '0');                     // cap@-24 = 0 (unused)
        $out .= $this->genStoreAt($fr, -16, '0');                     // len@-16 = 0 (unused)
        $out .= $this->genStoreAt($fr, -8, '1');                      // rc@-8 = 1
        $rp = $this->ssa->allocReg();
        $out .= '  ' . $rp . ' = ptrtoint ptr ' . $resume . " to i64\n";
        $out .= '  store i64 ' . $rp . ', ptr ' . $fr . "\n";        // resume_fn@0
        $out .= $this->genStoreAt($fr, 8, '0');                       // state@8 = 0
        $out .= $this->genStoreAt($fr, 16, '0');                      // current@16 = 0
        $out .= $this->genStoreAt($fr, 24, '0');                      // key@24 = 0
        $out .= $this->genStoreAt($fr, 32, '0');                      // nextkey@32 = 0
        // sent@40: the inbound yield-expression value (cell-typed). Default to a
        // boxed null so an unsent `$x = yield` reads NULL (not raw 0) and
        // var_dump / echo render it correctly; send()/throw() box their arg.
        $this->rt->needsTagged = true;
        $bn = $this->ssa->allocReg();
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
                $gep = $this->ssa->allocReg();
                $out .= '  ' . $gep . ' = getelementptr inbounds i64, ptr %env, i64 '
                      . (string)($capIndex[$name] + 1) . "\n";
                $cv = $this->ssa->allocReg();
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
        $ri = $this->ssa->allocReg();
        $out .= '  ' . $ri . ' = ptrtoint ptr ' . $fr . " to i64\n";
        $out .= '  ret i64 ' . $ri . "\n}\n\n";

        // ── resume ──
        $this->locals->slots = [];
        $this->locals->refLocals = [];
        $this->frame->returnType = $fn->returnType;
        $out .= 'define ' . $defLinkage . 'i64 ' . $resume . "(ptr %frame) {\nentry:\n";
        // Local slots = frame GEPs computed in entry (dominate every block).
        foreach ($locals as $name => $idx) {
            $off = self::GEN_HEADER + 8 * $idx;
            $slot = $this->ssa->allocReg();
            $out .= '  ' . $slot . ' = getelementptr inbounds i8, ptr %frame, i64 '
                  . (string)$off . "\n";
            $this->locals->slots[$name] = $slot;
        }
        $this->gen->statePtr = $this->ssa->allocReg();
        $out .= '  ' . $this->gen->statePtr . " = getelementptr inbounds i8, ptr %frame, i64 8\n";
        $this->gen->currentPtr = $this->ssa->allocReg();
        $out .= '  ' . $this->gen->currentPtr . " = getelementptr inbounds i8, ptr %frame, i64 16\n";
        $this->gen->keyPtr = $this->ssa->allocReg();
        $out .= '  ' . $this->gen->keyPtr . " = getelementptr inbounds i8, ptr %frame, i64 24\n";
        $this->gen->nextKeyPtr = $this->ssa->allocReg();
        $out .= '  ' . $this->gen->nextKeyPtr . " = getelementptr inbounds i8, ptr %frame, i64 32\n";
        $this->gen->sentPtr = $this->ssa->allocReg();
        $out .= '  ' . $this->gen->sentPtr . " = getelementptr inbounds i8, ptr %frame, i64 40\n";
        $this->gen->retvalPtr = $this->ssa->allocReg();
        $out .= '  ' . $this->gen->retvalPtr . " = getelementptr inbounds i8, ptr %frame, i64 48\n";
        $st = $this->ssa->allocReg();
        $out .= '  ' . $st . ' = load i64, ptr ' . $this->gen->statePtr . "\n";
        $nYields = $this->countYields($fn->body);
        $startLabel = $this->ssa->allocLabel('gen.start');
        $cases = '';
        for ($k = 1; $k <= $nYields; $k = $k + 1) {
            $cases .= '    i64 ' . (string)$k . ', label %gen.resume.' . (string)$k . "\n";
        }
        $out .= '  switch i64 ' . $st . ', label %' . $startLabel . " [\n" . $cases . "  ]\n";
        $out .= $startLabel . ":\n";

        $savedInGen = $this->gen->inGenerator;
        $savedCounter = $this->gen->yieldCounter;
        $this->gen->inGenerator = true;
        $this->gen->yieldCounter = 0;
        $out .= $this->emitNode($fn->body);
        $this->gen->inGenerator = $savedInGen;
        $this->gen->yieldCounter = $savedCounter;

        // Fell off the end → finished.
        $out .= '  store i64 -1, ptr ' . $this->gen->statePtr . "\n";
        $out .= "  ret i64 0\n}\n\n";
        return $out;
    }

    /** `store i64 <val>, ptr (base + off)` — a frame header/local write. */
    private function genStoreAt(string $base, int $off, string $val): string
    {
        if ($off === 0) {
            return '  store i64 ' . $val . ', ptr ' . $base . "\n";
        }
        $p = $this->ssa->allocReg();
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
            $name = $n->name;
            if (!isset($locals[$name])) { $locals[$name] = \count($locals); }
        } elseif ($n->kind === Node::KIND_TRY_CATCH) {
            $tc = $n;
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
            if (!isset($locals[$n->valueVar])) { $locals[$n->valueVar] = \count($locals); }
            if ($n->keyVar !== null && !isset($locals[$n->keyVar])) {
                $locals[$n->keyVar] = \count($locals);
            }
            // Iterator state that crosses a yield in the body must live in the
            // frame (the resume entry-switch re-enters mid-loop, killing SSA /
            // stack allocas). An ARRAY foreach needs two slots (cursor + array
            // ptr); a GENERATOR foreach needs one (the sub-generator ptr).
            if ($this->foreachBodyYields($n->body)) {
                $n->genSlotBase = \count($locals);
                $locals["@fe.0." . (string)$n->genSlotBase] = \count($locals);
                if (!$this->isGeneratorType($n->array->type)) {
                    $locals["@fe.1." . (string)$n->genSlotBase] = \count($locals);
                }
            }
        }
        foreach (\Compile\Mir\Walk::children($n) as $c) {
            $this->collectGenLocals($c, $locals);
        }
    }

    /**
     * `yield`, `yield $v`, `yield $k => $v` inside a resume body: store the
     * key + value into the frame, set `state` to this yield's index, and
     * `ret 1` (suspend). The matching `gen.resume.<k>` label (a switch
     * target) re-enters here; the yield EXPRESSION's value is then the value
     * passed in via `send()` (the frame's `sent` slot, 0/null otherwise).
     * The key is an explicit `$k` or the auto-increment `nextkey` counter.
     */
    private function emitYield(\Compile\Mir\Yield_ $n): string
    {
        if (!$this->gen->inGenerator) {
            throw new \RuntimeException('EmitLlvm: yield outside a generator');
        }
        $y = $n;
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
            $out .= '  store i64 ' . $this->lastValue . ', ptr ' . $this->gen->keyPtr . "\n";
        } else {
            $nk = $this->ssa->allocReg();
            $out .= '  ' . $nk . ' = load i64, ptr ' . $this->gen->nextKeyPtr . "\n";
            $out .= '  store i64 ' . $nk . ', ptr ' . $this->gen->keyPtr . "\n";
            $nk1 = $this->ssa->allocReg();
            $out .= '  ' . $nk1 . ' = add i64 ' . $nk . ", 1\n";
            $out .= '  store i64 ' . $nk1 . ', ptr ' . $this->gen->nextKeyPtr . "\n";
        }
        $out .= '  store i64 ' . $val . ', ptr ' . $this->gen->currentPtr . "\n";
        $k = $this->gen->yieldCounter + 1;
        $this->gen->yieldCounter = $k;
        $out .= '  store i64 ' . (string)$k . ', ptr ' . $this->gen->statePtr . "\n";
        $out .= "  ret i64 1\n";
        $out .= 'gen.resume.' . (string)$k . ":\n";
        // `$gen->throw($e)` injection: on resume, a pending exception makes the
        // suspended `yield` expression raise (caught by an enclosing try in the
        // generator, else propagated to the consumer via the jmp stack). The
        // longjmp targets depth-1 — the generator's own try setjmp (left at this
        // depth by the suspend; yield-ret doesn't pop it) or the consumer's.
        if ($this->gen->throwUsed) {
            $gt = $this->ssa->allocReg();
            $out .= '  ' . $gt . " = load ptr, ptr @__mir_gen_throw\n";
            $inj = $this->ssa->allocReg();
            $out .= '  ' . $inj . ' = icmp ne ptr ' . $gt . ", null\n";
            $thrL = $this->ssa->allocLabel('gen.inject');
            $contL = $this->ssa->allocLabel('gen.resumed');
            $out .= '  br i1 ' . $inj . ', label %' . $thrL . ', label %' . $contL . "\n";
            $out .= $thrL . ":\n";
            $out .= "  store ptr null, ptr @__mir_gen_throw\n";
            $out .= '  store ptr ' . $gt . ", ptr @__mir_thrown\n";
            $d = $this->ssa->allocReg();
            $out .= '  ' . $d . " = load i64, ptr @__mir_jmp_depth\n";
            $s = $this->ssa->allocReg();
            $out .= '  ' . $s . ' = sub i64 ' . $d . ", 1\n";
            $out .= $this->jmpBufExpr($s);
            $out .= '  call void @longjmp(ptr ' . $this->jmpScratch . ", i32 1)\n";
            $out .= "  unreachable\n";
            $out .= $contL . ":\n";
        }
        // Resumed: the yield expression evaluates to the sent-in value.
        $sent = $this->ssa->allocReg();
        $out .= '  ' . $sent . ' = load i64, ptr ' . $this->gen->sentPtr . "\n";
        $this->lastValue = $sent;
        $this->lastValueType = 'i64';
        return $out;
    }

    /** Indirectly call a generator's resume fn (ptr at frame@0). */
    private function genResumeCall(string $frame): string
    {
        $fnw = $this->ssa->allocReg();
        $out  = '  ' . $fnw . ' = load i64, ptr ' . $frame . "\n";
        $fp = $this->ssa->allocReg();
        $out .= '  ' . $fp . ' = inttoptr i64 ' . $fnw . " to ptr\n";
        $rr = $this->ssa->allocReg();
        $out .= '  ' . $rr . ' = call i64 ' . $fp . '(ptr ' . $frame . ")\n";
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
        $p = $this->ssa->allocReg();
        $this->lastValue = $p;
        $this->lastValueType = 'ptr';
        return '  ' . $p . ' = getelementptr inbounds i8, ptr %frame, i64 '
             . (string)(self::GEN_HEADER + 8 * $idx) . "\n";
    }

    /** Reload the frame-stored array ptr into a fresh SSA; sets lastValue. */
    private function genReloadArr(string $arrSlot): string
    {
        $ai = $this->ssa->allocReg();
        $out = '  ' . $ai . ' = load i64, ptr ' . $arrSlot . "\n";
        $ap = $this->ssa->allocReg();
        $out .= '  ' . $ap . ' = inttoptr i64 ' . $ai . " to ptr\n";
        $this->lastValue = $ap;
        $this->lastValueType = 'ptr';
        return $out;
    }
}
