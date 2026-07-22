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
 * Structured control flow: if / while / for / do-while / switch / match /
 * foreach, and the ternary. Loop targets live in {@see \Compile\Mir\ControlFlow}.
 *
 * A trait on the one {@see EmitLlvm} host — the split is by concern, so a reader
 * opens the file for the thing they are looking at instead of scrolling one
 * 8k-line class. State stays on the host and its collaborators.
 */
trait EmitLlvmControl
{
    /** True when a foreach body contains a `yield` (its iterator state then
     *  crosses a suspension and must live in the frame). */
    private function foreachBodyYields(Node $body): bool
    {
        return $this->countYields($body) > 0;
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
        if (!isset($this->locals->slots[$fe->valueVar])) {
            $vs = $this->ssa->allocReg();
            $this->locals->slots[$fe->valueVar] = $vs;
            $out .= '  ' . $vs . " = alloca i64\n";
        }
        if ($fe->keyVar !== null && !isset($this->locals->slots[$fe->keyVar])) {
            $ks = $this->ssa->allocReg();
            $this->locals->slots[$fe->keyVar] = $ks;
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
            $gSlot = $this->locals->slots["@fe.0." . (string)$fe->genSlotBase];
            $gi = $this->ssa->allocReg();
            $out .= '  ' . $gi . ' = ptrtoint ptr ' . $g . " to i64\n";
            $out .= '  store i64 ' . $gi . ', ptr ' . $gSlot . "\n";
        }

        $rewindLabel = $this->ssa->allocLabel('feg.rewind');
        $condLabel = $this->ssa->allocLabel('feg.cond');
        $bodyLabel = $this->ssa->allocLabel('feg.body');
        $stepLabel = $this->ssa->allocLabel('feg.step');
        $endLabel  = $this->ssa->allocLabel('feg.end');

        // rewind: resume once if not yet started (state == 0).
        $out .= $this->genFieldLoad($g, 8);
        $st0 = $this->lastValue;
        $fresh = $this->ssa->allocReg();
        $out .= '  ' . $fresh . ' = icmp eq i64 ' . $st0 . ", 0\n";
        $out .= '  br i1 ' . $fresh . ', label %' . $rewindLabel . ', label %' . $condLabel . "\n";
        $out .= $rewindLabel . ":\n";
        $out .= $this->genResumeCall($g);
        $out .= '  br label %' . $condLabel . "\n";

        $out .= $condLabel . ":\n";
        if ($framed) { $out .= $this->genReloadArr($gSlot); $g = $this->lastValue; }
        $out .= $this->genFieldLoad($g, 8);
        $st = $this->lastValue;
        $fin = $this->ssa->allocReg();
        $out .= '  ' . $fin . ' = icmp eq i64 ' . $st . ", -1\n";
        $out .= '  br i1 ' . $fin . ', label %' . $endLabel . ', label %' . $bodyLabel . "\n";

        $out .= $bodyLabel . ":\n";
        if ($framed) { $out .= $this->genReloadArr($gSlot); $g = $this->lastValue; }
        $out .= $this->genFieldLoad($g, 16);
        $cur = $this->lastValue;
        $out .= '  store i64 ' . $cur . ', ptr ' . $this->locals->slots[$fe->valueVar] . "\n";
        if ($fe->keyVar !== null) {
            $out .= $this->genFieldLoad($g, 24);
            $out .= '  store i64 ' . $this->lastValue . ', ptr ' . $this->locals->slots[$fe->keyVar] . "\n";
        }
        $this->cf->enterLoop($endLabel, $stepLabel);
        $out .= $this->emitNode($fe->body);
        $this->cf->leave();
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
            $this->rt->needsStrRc = true;
            $out .= '  call void @__mir_rc_release_str(ptr ' . $relPtr . ")\n";
        }
        $this->lastValue = '0';
        $this->lastValueType = 'i64';
        return $out;
    }

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
        if (!isset($this->locals->slots[$fe->valueVar])) {
            $vs = $this->ssa->allocReg();
            $this->locals->slots[$fe->valueVar] = $vs;
            $out .= '  ' . $vs . " = alloca i64\n";
        }
        if ($fe->keyVar !== null && !isset($this->locals->slots[$fe->keyVar])) {
            $ks = $this->ssa->allocReg();
            $this->locals->slots[$fe->keyVar] = $ks;
            $out .= '  ' . $ks . " = alloca i64\n";
        }
        // Hold the iterator in a synthetic local; protocol calls load it from
        // there so the subject expression is evaluated exactly once.
        $iterName = "@it." . (string)$this->iterCounter;
        $this->iterCounter = $this->iterCounter + 1;
        $iterSlot = $this->ssa->allocReg();
        $this->locals->slots[$iterName] = $iterSlot;
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

        $condL = $this->ssa->allocLabel('feo.cond');
        $bodyL = $this->ssa->allocLabel('feo.body');
        $stepL = $this->ssa->allocLabel('feo.step');
        $endL  = $this->ssa->allocLabel('feo.end');
        $out .= '  br label %' . $condL . "\n";

        $out .= $condL . ":\n";
        $out .= $this->emitNode(new \Compile\Mir\MethodCall_($iterNode, 'valid', [], \Compile\Mir\Type::bool_()));
        $out .= $this->coerceToI64();
        $v = $this->ssa->allocReg();
        $out .= '  ' . $v . ' = icmp ne i64 ' . $this->lastValue . ", 0\n";
        $out .= '  br i1 ' . $v . ', label %' . $bodyL . ', label %' . $endL . "\n";

        $out .= $bodyL . ":\n";
        $out .= $this->emitNode(new \Compile\Mir\MethodCall_($iterNode, 'current', [], \Compile\Mir\Type::unknown()));
        $out .= $this->coerceToI64();
        $out .= '  store i64 ' . $this->lastValue . ', ptr ' . $this->locals->slots[$fe->valueVar] . "\n";
        if ($fe->keyVar !== null) {
            $out .= $this->emitNode(new \Compile\Mir\MethodCall_($iterNode, 'key', [], \Compile\Mir\Type::unknown()));
            $out .= $this->coerceToI64();
            $out .= '  store i64 ' . $this->lastValue . ', ptr ' . $this->locals->slots[$fe->keyVar] . "\n";
        }
        $this->cf->enterLoop($endL, $stepL);
        $out .= $this->emitNode($fe->body);
        $this->cf->leave();
        $out .= '  br label %' . $stepL . "\n";

        $out .= $stepL . ":\n";
        $out .= $this->emitNode(new \Compile\Mir\MethodCall_($iterNode, 'next', [], \Compile\Mir\Type::void()));
        $out .= '  br label %' . $condL . "\n";

        $out .= $endL . ":\n";
        $this->lastValue = '0';
        $this->lastValueType = 'i64';
        return $out;
    }

    /**
     * After an unconditional terminator (`br`, `ret`) the next
     * instructions still need to live in a labeled block, otherwise
     * LLVM rejects the IR. Emit a fresh dead label callers fall
     * through into.
     */
    private function emitDeadLabel(): string
    {
        $label = $this->ssa->allocLabel('dead');
        return $label . ":\n";
    }

    private function emitTernary(Ternary $n): string
    {
        $t = $n;
        $res = $this->ssa->allocReg();
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
        $thenLabel = $this->ssa->allocLabel('tern.then');
        $elseLabel = $this->ssa->allocLabel('tern.else');
        $endLabel  = $this->ssa->allocLabel('tern.end');
        $condBit = $this->ssa->allocReg();
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
        $loaded = $this->ssa->allocReg();
        $out .= '  ' . $loaded . ' = load i64, ptr ' . $res . "\n";
        $this->lastValue = $loaded;
        $this->lastValueType = 'i64';
        if ($n->type->kind === Type::KIND_FLOAT) {
            $regF = $this->ssa->allocReg();
            $out .= '  ' . $regF . ' = bitcast i64 ' . $loaded . " to double\n";
            $this->lastValue = $regF;
            $this->lastValueType = 'double';
        }
        return $out;
    }

    private function emitForeach(Foreach_ $n): string
    {
        $fe = $n;
        if ($this->isGeneratorType($fe->array->type)) {
            return $this->emitForeachGenerator($fe);
        }
        if ($this->isTraversableType($fe->array->type)) {
            return $this->emitForeachObject($fe);
        }
        $out = '';
        if (!isset($this->locals->slots[$fe->valueVar])) {
            $vs = $this->ssa->allocReg();
            $this->locals->slots[$fe->valueVar] = $vs;
            $out .= '  ' . $vs . " = alloca i64\n";
        }
        if ($fe->keyVar !== null && !isset($this->locals->slots[$fe->keyVar])) {
            $ks = $this->ssa->allocReg();
            $this->locals->slots[$fe->keyVar] = $ks;
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
        $nz = $this->ssa->allocReg();
        $out .= '  ' . $nz . ' = icmp eq ptr ' . $arr . ", null\n";
        $arrSafe = $this->ssa->allocReg();
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
            $iSlot = $this->locals->slots["@fe.0." . (string)$fe->genSlotBase];
            $arrSlot = $this->locals->slots["@fe.1." . (string)$fe->genSlotBase];
            $out .= '  store i64 0, ptr ' . $iSlot . "\n";
            // Compact out tombstones (holes) ONCE before the loop so the
            // per-iteration length reloads and element addressing see a clean
            // 0..len range. A never-unset array (the common case) short-circuits
            // inside live_len with just a flags check.
            $clv = $this->ssa->allocReg();
            $out .= '  ' . $clv . ' = call i64 @__mir_array_live_len(ptr ' . $arr . ")\n";
            $aint = $this->ssa->allocReg();
            $out .= '  ' . $aint . ' = ptrtoint ptr ' . $arr . " to i64\n";
            $out .= '  store i64 ' . $aint . ', ptr ' . $arrSlot . "\n";
            $len = '0'; // recomputed in cond (reloaded array)
        } else {
            $iSlot = $this->ssa->allocReg();
            $out .= '  ' . $iSlot . " = alloca i64\n";
            $out .= '  store i64 0, ptr ' . $iSlot . "\n";
            // live_len compacts out tombstones once, then returns the clean len.
            $len = $this->ssa->allocReg();
            $out .= '  ' . $len . ' = call i64 @__mir_array_live_len(ptr ' . $arr . ")\n";
        }

        $condLabel = $this->ssa->allocLabel('fe.cond');
        $bodyLabel = $this->ssa->allocLabel('fe.body');
        $stepLabel = $this->ssa->allocLabel('fe.step');
        $endLabel  = $this->ssa->allocLabel('fe.end');
        $this->cf->enterLoop($endLabel, $stepLabel);

        // Per-iteration arena reset. Safe because the save point is taken
        // *after* the iterable + iterator state (`$arr`, `$iSlot`, `$len`)
        // are materialized, so a reset never frees the array being walked.
        // By-ref foreach writes the value slot back into the element, so an
        // arena value could escape into the (pre-save) array — skip it.
        $reset = !$fe->byRef && $this->arena->canResetPerIteration(null, $fe->body, null, $this->frame->body, $this->gen->inGenerator);
        if ($reset) { $out .= $this->emitArenaSave(); }

        $out .= '  br label %' . $condLabel . "\n";
        $out .= $condLabel . ":\n";
        if ($reset) { $out .= $this->emitArenaReset(); }
        if ($framed) {
            $out .= $this->genReloadArr($arrSlot);
            $arr = $this->lastValue;
            $len = $this->ssa->allocReg();
            $out .= '  ' . $len . ' = load i64, ptr ' . $arr . "\n";
        }
        $i = $this->ssa->allocReg();
        $out .= '  ' . $i . ' = load i64, ptr ' . $iSlot . "\n";
        $c = $this->ssa->allocReg();
        $out .= '  ' . $c . ' = icmp slt i64 ' . $i . ', ' . $len . "\n";
        $out .= '  br i1 ' . $c . ', label %' . $bodyLabel . ', label %' . $endLabel . "\n";

        $out .= $bodyLabel . ":\n";
        if ($framed) { $out .= $this->genReloadArr($arrSlot); $arr = $this->lastValue; }
        // element address + key
        $out .= $this->foreachElemAddrUnified($arr, $i);
        $valAddr = $this->feAddr;
        $valSlot = $this->locals->slots[$fe->valueVar];
        $ev = $this->ssa->allocReg();
        $out .= '  ' . $ev . ' = load i64, ptr ' . $valAddr . "\n";
        $out .= '  store i64 ' . $ev . ', ptr ' . $valSlot . "\n";
        if ($fe->keyVar !== null) {
            $kSlot = $this->locals->slots[$fe->keyVar];
            // key_at handles packed (index) vs hashed (int / str ptr). Over a
            // `mixed`/cell, an erased/unknown, OR a cell-element array (which may
            // hold dynamic int-or-string keys) the key must come back NaN-boxed,
            // so route to the cell-boxing variant — matches the cell key type
            // InferTypes assigns there, so a downstream `$out[$k]=…` dispatches
            // by tag (set_cell).
            $kp = $this->ssa->allocReg();
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
        $si = $this->ssa->allocReg();
        $out .= '  ' . $si . ' = load i64, ptr ' . $iSlot . "\n";
        if ($fe->byRef) {
            $out .= $this->foreachElemAddrUnified($arr, $si);
            $wAddr = $this->feAddr;
            $wv = $this->ssa->allocReg();
            $out .= '  ' . $wv . ' = load i64, ptr ' . $this->locals->slots[$fe->valueVar] . "\n";
            $out .= '  store i64 ' . $wv . ', ptr ' . $wAddr . "\n";
        }
        $si2 = $this->ssa->allocReg();
        $out .= '  ' . $si2 . ' = add i64 ' . $si . ", 1\n";
        $out .= '  store i64 ' . $si2 . ', ptr ' . $iSlot . "\n";
        $out .= '  br label %' . $condLabel . "\n";
        $out .= $endLabel . ":\n";

        $this->cf->leave();
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
        $fa = $this->ssa->allocReg();
        $out  = '  ' . $fa . ' = getelementptr inbounds i8, ptr ' . $arr . ', i64 ' . $fo . "\n";
        $fl = $this->ssa->allocReg();
        $out .= '  ' . $fl . ' = load i64, ptr ' . $fa . "\n";
        $flm = $this->ssa->allocReg();
        $out .= '  ' . $flm . ' = and i64 ' . $fl . ', ' . (string)\Compile\MemoryAbi::ARRAY_FLAG_HASHED . "\n";
        $ish = $this->ssa->allocReg();
        $out .= '  ' . $ish . ' = icmp ne i64 ' . $flm . ", 0\n";
        $po0 = $this->ssa->allocReg();
        $out .= '  ' . $po0 . ' = mul i64 ' . $i . ', ' . (string)\Compile\MemoryAbi::ARRAY_PACKED_ELEMENT_SIZE . "\n";
        $po = $this->ssa->allocReg();
        $out .= '  ' . $po . ' = add i64 ' . $po0 . ', ' . $H . "\n";
        $pa = $this->ssa->allocReg();
        $out .= '  ' . $pa . ' = getelementptr inbounds i8, ptr ' . $arr . ', i64 ' . $po . "\n";
        $ho0 = $this->ssa->allocReg();
        $out .= '  ' . $ho0 . ' = mul i64 ' . $i . ', ' . $E . "\n";
        $ho = $this->ssa->allocReg();
        $out .= '  ' . $ho . ' = add i64 ' . $ho0 . ', ' . (string)(\Compile\MemoryAbi::ARRAY_HEADER_SIZE + \Compile\MemoryAbi::ARRAY_ENTRY_VALUE_OFFSET) . "\n";
        $ha = $this->ssa->allocReg();
        $out .= '  ' . $ha . ' = getelementptr inbounds i8, ptr ' . $arr . ', i64 ' . $ho . "\n";
        $addr = $this->ssa->allocReg();
        $out .= '  ' . $addr . ' = select i1 ' . $ish . ', ptr ' . $ha . ', ptr ' . $pa . "\n";
        $this->feAddr = $addr;
        return $out;
    }

    private function emitSwitch(Switch_ $n): string
    {
        $sw = $n;
        $out = $this->emitNode($sw->subject);
        $out .= $this->coerceToI64();
        $subj = $this->lastValue;
        $endLabel = $this->ssa->allocLabel('sw.end');
        // A switch counts as a break/continue level; continue inside a
        // switch behaves as break (target = end).
        $this->cf->enterSwitch($endLabel);

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
                $eq = $this->ssa->allocReg();
                if ($subjK === Type::KIND_CELL) {
                    // A cell (untyped/`mixed`) subject is NaN-boxed, so a raw
                    // `icmp eq` of its boxed bits against a raw arm value never
                    // matches (a boxed int 1 != raw 1) and misses `5 == "5"`.
                    // PHP `switch` matches with `==`, so box the arm and run the
                    // loose-juggling tagged compare (mirrors emitCmp's cell path).
                    $out .= $this->boxToCell($arm->value->type);
                    $armCell = $this->lastValue;
                    $this->rt->needsTaggedEq = true;
                    $this->rt->needsTagged = true;
                    $this->rt->needsTaggedToFloat = true;
                    $le = $this->ssa->allocReg();
                    $out .= '  ' . $le . ' = call i64 @__manticore_tagged_loose_eq(i64 '
                          . $subj . ', i64 ' . $armCell . ")\n";
                    $out .= '  ' . $eq . ' = icmp ne i64 ' . $le . ", 0\n";
                } else {
                    $out .= $this->coerceToI64();
                    $v = $this->lastValue;
                    $useStr = ($subjK === Type::KIND_STRING || $vk === Type::KIND_STRING)
                        && $subjStrish && ($vk === Type::KIND_STRING || $vk === Type::KIND_UNKNOWN);
                    // PHP `switch` matches with `==`, so the same juggling rows
                    // emitCmp routes apply here: two NUMERIC strings match
                    // (`case "1e1"` on "10"), and a subject and arm of DIFFERENT
                    // kinds juggle (`switch ("10") { case 10: }` matched nothing
                    // — a raw icmp compared a string POINTER against 10).
                    $jug = [
                        Type::KIND_INT => true, Type::KIND_FLOAT => true, Type::KIND_STRING => true,
                        Type::KIND_BOOL => true, Type::KIND_ARRAY => true, Type::KIND_OBJ => true,
                    ];
                    $bothStr = $subjK === Type::KIND_STRING && $vk === Type::KIND_STRING;
                    if ($useStr) {
                        $this->rt->needsStrcmp = true;
                        $eqFn = '@__mir_str_eq';
                        if ($bothStr) {
                            $eqFn = '@__mir_str_loose_eq';
                            $this->rt->needsTaggedEq = true;
                            $this->rt->needsStrtod = true;
                        }
                        $sp = $this->ssa->allocReg();
                        $out .= '  ' . $sp . ' = inttoptr i64 ' . $subj . " to ptr\n";
                        $vp = $this->ssa->allocReg();
                        $out .= '  ' . $vp . ' = inttoptr i64 ' . $v . " to ptr\n";
                        $out .= '  ' . $eq . ' = call i1 ' . $eqFn . '(ptr ' . $sp . ', ptr ' . $vp . ")\n";
                    } elseif ($subjK !== $vk && isset($jug[$subjK]) && isset($jug[$vk])) {
                        $this->rt->needsTaggedEq = true;
                        $this->lastValue = $subj; $this->lastValueType = 'i64';
                        $out .= $this->shallowBoxToCell($sw->subject->type);
                        $sc = $this->lastValue;
                        $this->lastValue = $v; $this->lastValueType = 'i64';
                        $out .= $this->shallowBoxToCell($arm->value->type);
                        $ac = $this->lastValue;
                        $le = $this->ssa->allocReg();
                        $out .= '  ' . $le . ' = call i64 @__manticore_tagged_loose_eq(i64 '
                              . $sc . ', i64 ' . $ac . ")\n";
                        $out .= '  ' . $eq . ' = icmp ne i64 ' . $le . ", 0\n";
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
        $this->cf->leave();
        return $out;
    }

    private function emitMatch(Match_ $n): string
    {
        $m = $n;
        $res = $this->ssa->allocReg();
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
        $endLabel = $this->ssa->allocLabel('match.end');
        foreach ($m->arms as $arm) {
            $bodyLabel = $this->ssa->allocLabel('match.body');
            $afterLabel = $this->ssa->allocLabel('match.after');
            $conds = $arm->conds;
            if ($conds === null) {
                $out .= '  br label %' . $bodyLabel . "\n";
            } else {
                foreach ($conds as $c) {
                    $vk = $this->nodeTypeKind($c);
                    $eq = $this->ssa->allocReg();
                    if ($subjIsCell) {
                        if ($vk === Type::KIND_STRING || $vk === Type::KIND_UNKNOWN) {
                            // string cond: tag-guarded strcmp (a non-string
                            // subject is never strictly === a string).
                            $out .= $this->emitCellStrEq($subj, $c, $eq);
                        } else {
                            // int/bool/null cond: unbox the subject's payload
                            // once, then `icmp eq` against the raw cond value.
                            if ($subjInt === '') {
                                $this->rt->needsTagged = true;
                                $subjInt = $this->ssa->allocReg();
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
                            $this->rt->needsStrcmp = true;
                            $sp = $this->ssa->allocReg();
                            $out .= '  ' . $sp . ' = inttoptr i64 ' . $subj . " to ptr\n";
                            $cp = $this->ssa->allocReg();
                            $out .= '  ' . $cp . ' = inttoptr i64 ' . $cv . " to ptr\n";
                            $out .= '  ' . $eq . ' = call i1 @__mir_str_eq(ptr ' . $sp . ', ptr ' . $cp . ")\n";
                        } else {
                            $out .= '  ' . $eq . ' = icmp eq i64 ' . $subj . ', ' . $cv . "\n";
                        }
                    }
                    $condNext = $this->ssa->allocLabel('match.cond');
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
        $loaded = $this->ssa->allocReg();
        $out .= '  ' . $loaded . ' = load i64, ptr ' . $res . "\n";
        $this->lastValue = $loaded;
        $this->lastValueType = 'i64';
        if ($n->type->kind === Type::KIND_FLOAT) {
            $regF = $this->ssa->allocReg();
            $out .= '  ' . $regF . ' = bitcast i64 ' . $loaded . " to double\n";
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

    private function emitIf(If_ $n): string
    {
        $i = $n;
        $out = $this->emitCondVal($i->cond);
        $cond = $this->lastValue;
        $thenLabel = $this->ssa->allocLabel('then');
        $elseLabel = $i->else === null ? $this->ssa->allocLabel('endif') : $this->ssa->allocLabel('else');
        $endLabel = $i->else === null ? $elseLabel : $this->ssa->allocLabel('endif');
        // Truncate i64 → i1 for the branch condition.
        $condBit = $this->ssa->allocReg();
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

    private function emitWhile(While_ $n): string
    {
        $w = $n;
        $condLabel = $this->ssa->allocLabel('loop.cond');
        $bodyLabel = $this->ssa->allocLabel('loop.body');
        $endLabel  = $this->ssa->allocLabel('loop.end');
        $this->cf->enterLoop($endLabel, $condLabel);

        $reset = $this->arena->canResetPerIteration($w->cond, $w->body, null, $this->frame->body, $this->gen->inGenerator);
        $out = '';
        if ($reset) { $out .= $this->emitArenaSave(); }
        $out .= '  br label %' . $condLabel . "\n";
        $out .= $condLabel . ":\n";
        if ($reset) { $out .= $this->emitArenaReset(); }
        $out .= $this->emitCondVal($w->cond);
        $cond = $this->lastValue;
        $condBit = $this->ssa->allocReg();
        $out .= '  ' . $condBit . ' = icmp ne i64 ' . $cond . ", 0\n";
        $out .= '  br i1 ' . $condBit . ', label %' . $bodyLabel . ', label %' . $endLabel . "\n";
        $out .= $bodyLabel . ":\n";
        $out .= $this->emitNode($w->body);
        $out .= '  br label %' . $condLabel . "\n";
        $out .= $endLabel . ":\n";

        $this->cf->leave();
        return $out;
    }

    private function emitFor(For_ $n): string
    {
        $f = $n;
        $condLabel = $this->ssa->allocLabel('for.cond');
        $bodyLabel = $this->ssa->allocLabel('for.body');
        $stepLabel = $this->ssa->allocLabel('for.step');
        $endLabel  = $this->ssa->allocLabel('for.end');
        // `continue` runs the step before re-testing the condition.
        $this->cf->enterLoop($endLabel, $stepLabel);

        $reset = $this->arena->canResetPerIteration($f->cond, $f->body, $f->step, $this->frame->body, $this->gen->inGenerator);
        $out = '';
        if ($f->init !== null) { $out .= $this->emitNode($f->init); }
        if ($reset) { $out .= $this->emitArenaSave(); }
        $out .= '  br label %' . $condLabel . "\n";
        $out .= $condLabel . ":\n";
        if ($reset) { $out .= $this->emitArenaReset(); }
        if ($f->cond !== null) {
            $out .= $this->emitCondVal($f->cond);
            $cond = $this->lastValue;
            $condBit = $this->ssa->allocReg();
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

        $this->cf->leave();
        return $out;
    }

    private function emitDoWhile(DoWhile_ $n): string
    {
        $d = $n;
        $bodyLabel = $this->ssa->allocLabel('do.body');
        $condLabel = $this->ssa->allocLabel('do.cond');
        $endLabel  = $this->ssa->allocLabel('do.end');
        $this->cf->enterLoop($endLabel, $condLabel);

        $reset = $this->arena->canResetPerIteration($d->cond, $d->body, null, $this->frame->body, $this->gen->inGenerator);
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
        $condBit = $this->ssa->allocReg();
        $out .= '  ' . $condBit . ' = icmp ne i64 ' . $cond . ", 0\n";
        $out .= '  br i1 ' . $condBit . ', label %' . $bodyLabel . ', label %' . $endLabel . "\n";
        $out .= $endLabel . ":\n";

        $this->cf->leave();
        return $out;
    }
}
