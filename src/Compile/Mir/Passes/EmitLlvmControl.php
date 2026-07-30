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
        $out = $this->emitNode($fe->array);
        $out .= $this->coerceToPtr();
        return $out . $this->emitForeachGeneratorFrom($fe, $this->lastValue);
    }

    /**
     * The generator loop itself, over an ALREADY-materialized frame `ptr`. Split
     * out so the erased-base foreach can reach it after its runtime classify
     * without evaluating the subject a second time (which would run its side
     * effects twice).
     */
    private function emitForeachGeneratorFrom(\Compile\Mir\Foreach_ $fe, string $g, bool $ownsFrame = true): string
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
        // `current`@16 is a tagged cell ({@see EmitLlvmGenerator::emitYield}) —
        // unbox to whatever this loop's value var is typed as. For a cell or
        // erased element that is a no-op and the slot keeps the self-describing
        // cell, which is the shape the runtime classifiers want.
        $gelem = Type::unknown();
        $gt = $fe->array->type;
        if ($gt->element !== null) { $gelem = $gt->element; }
        $out .= $this->unboxCellToType($gelem);
        $out .= $this->coerceToI64();
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
        if ($ownsFrame
            && ($ak === Node::KIND_CALL || $ak === Node::KIND_METHOD_CALL
                || $ak === Node::KIND_STATIC_CALL || $ak === Node::KIND_INVOKE)) {
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
    /**
     * The iterator's static class is an INTERFACE with no descriptor —
     * `getIterator(): \Traversable` resolves to nothing a virtual dispatch can
     * use, and a Generator satisfies it without being a class at all. symfony's
     * `TableRows implements IteratorAggregate` returns its generator closure
     * that way, so every protocol call missed and `Table::render` iterated its
     * rows ZERO times: borders drawn around no cells.
     */
    private function iterNeedsRuntimeClass(string $cls): bool
    {
        if ($cls === '' || $cls === 'Generator') { return false; }
        return !isset($this->classes[$cls]);
    }

    /**
     * Is this i64 carrier a GENERATOR FRAME? A frame borrows the string rc
     * header, so `ptr-8` holds a plain count and says nothing — its identity is
     * the magic the creator stamps in the otherwise-unused `cap@-24`
     * ({@see \Compile\MemoryAbi::GENERATOR_TAG_MAGIC}).
     *
     * Three steps, and each is load-bearing:
     *  1. bounded both ends ({@see plausiblePtrIr}) — an unboxed word IS its
     *     double bits, and a small int would dereference 0xFFFF…F9;
     *  2. `ptr-8` against the CONTAINER magics — an array or an object is
     *     positively identified there, and this stops step 3 from reading
     *     `-24` on something with no 32-byte header;
     *  3. `ptr-24` against the frame magic. What reaches here is a string or a
     *     frame, both of which carry the full header, so the read is in bounds.
     *
     * The result i1 is left in {@see EmitLlvm::$genFrameReg}.
     */
    /**
     * `(w > 0xFFF0…) ? (w & PAYLOAD_MASK) : w` — the payload of a NaN-boxed
     * carrier, or the word itself when it was never boxed. A conditional mask,
     * not the unconditional one {@see EmitLlvmBuiltins::cellToPtr} uses: an
     * untagged 64-bit INT must not be truncated into something that then looks
     * like a plausible pointer to probe. lastValue ← the untagged i64.
     */
    private function untagCarrierIr(string $word): string
    {
        $isBox = $this->ssa->allocReg();
        $out  = '  ' . $isBox . ' = icmp ugt i64 ' . $word . ", -4503599627370496\n";
        $m = $this->ssa->allocReg();
        $out .= '  ' . $m . ' = and i64 ' . $word . ", 281474976710655\n";
        $r = $this->ssa->allocReg();
        $out .= '  ' . $r . ' = select i1 ' . $isBox . ', i64 ' . $m . ', i64 ' . $word . "\n";
        $this->lastValue = $r;
        $this->lastValueType = 'i64';
        return $out;
    }

    /**
     * True when this module knows any class that can drive the Iterator
     * protocol — nothing else can reach {@see emitForeachErasedIterator}, and
     * the arm costs a second copy of the loop body, so a program without one
     * must not pay for it.
     */
    private function hasTraversableClasses(): bool
    {
        foreach ($this->classes as $cls) {
            if ($cls->isStruct) { continue; }
            if ($this->classImplements($cls->name, 'Iterator')
                || $this->classImplements($cls->name, 'IteratorAggregate')) {
                return true;
            }
        }
        return false;
    }

    /** `plausible ptr && the word at ptr-8 is an OBJECT magic` — the runtime
     *  "is this an object" test; the i1 lands in {@see $objectProbeReg}. */
    private function objectProbeIr(string $iw): string
    {
        $slot = $this->ssa->allocReg();
        $out  = '  ' . $slot . " = alloca i1\n";
        $out .= '  store i1 0, ptr ' . $slot . "\n";
        $probeL = $this->ssa->allocLabel('op.probe');
        $endL = $this->ssa->allocLabel('op.end');
        $out .= $this->plausiblePtrIr($iw);
        $out .= '  br i1 ' . $this->plausiblePtrReg . ', label %' . $probeL
              . ', label %' . $endL . "\n";
        $out .= $probeL . ":\n";
        $rp = $this->ssa->allocReg();
        $out .= '  ' . $rp . ' = inttoptr i64 ' . $iw . " to ptr\n";
        $tp = $this->ssa->allocReg();
        $out .= '  ' . $tp . ' = getelementptr inbounds i8, ptr ' . $rp . ", i64 -8\n";
        $tw = $this->ssa->allocReg();
        $out .= '  ' . $tw . ' = load i64, ptr ' . $tp . "\n";
        $isObj = $this->magicMatchIr($tw, [\Compile\MemoryAbi::RC_TAG_MAGIC]);
        $out .= $this->magicMatchOut;
        $out .= '  store i1 ' . $isObj . ', ptr ' . $slot . "\n";
        $out .= '  br label %' . $endL . "\n";
        $out .= $endL . ":\n";
        $r = $this->ssa->allocReg();
        $out .= '  ' . $r . ' = load i1, ptr ' . $slot . "\n";
        $this->objectProbeReg = $r;
        return $out;
    }

    /**
     * Drive the Iterator protocol over an erased word already known to be an
     * OBJECT. Which protocol it speaks is a runtime question — an
     * IteratorAggregate hands over its `getIterator()` result (often a
     * Generator, which the dynamic protocol step then recognises), an Iterator
     * speaks for itself — so both are emitted and `instanceof` picks.
     */
    private function emitForeachErasedIterator(\Compile\Mir\Foreach_ $fe, string $word): string
    {
        $subjName = '@fe.subj.' . (string)$this->iterCounter;
        $iterName = '@fe.it.' . (string)$this->iterCounter;
        $this->iterCounter = $this->iterCounter + 1;
        $subjSlot = $this->ssa->allocReg();
        $iterSlot = $this->ssa->allocReg();
        $this->locals->slots[$subjName] = $subjSlot;
        $this->locals->slots[$iterName] = $iterSlot;
        $out  = '  ' . $subjSlot . " = alloca i64\n";
        $out .= '  ' . $iterSlot . " = alloca i64\n";
        $out .= '  store i64 ' . $word . ', ptr ' . $subjSlot . "\n";
        $out .= '  store i64 ' . $word . ', ptr ' . $iterSlot . "\n";
        $aggL = $this->ssa->allocLabel('fe.agg');
        $joinL = $this->ssa->allocLabel('fe.agg.end');
        $probe = new \Compile\Mir\Instanceof_(
            new \Compile\Mir\LoadLocal($subjName, \Compile\Mir\Type::unknown()),
            'IteratorAggregate');
        $out .= $this->emitNode($probe);
        $out .= $this->coerceToI64();
        $isAgg = $this->ssa->allocReg();
        $out .= '  ' . $isAgg . ' = icmp ne i64 ' . $this->lastValue . ", 0\n";
        $out .= '  br i1 ' . $isAgg . ', label %' . $aggL . ', label %' . $joinL . "\n";
        $out .= $aggL . ":\n";
        $gi = new \Compile\Mir\MethodCall_(
            new \Compile\Mir\LoadLocal($subjName, \Compile\Mir\Type::obj('IteratorAggregate')),
            'getIterator', [], \Compile\Mir\Type::obj('Iterator'));
        $out .= $this->emitNode($gi);
        $out .= $this->coerceToI64();
        $out .= '  store i64 ' . $this->lastValue . ', ptr ' . $iterSlot . "\n";
        $out .= '  br label %' . $joinL . "\n";
        $out .= $joinL . ":\n";
        return $out . $this->emitIterProtocolLoop(
            $fe, $iterSlot, $iterName, \Compile\Mir\Type::obj('Iterator'), true);
    }

    private function genFrameProbeIr(string $iw): string
    {
        $slot = $this->ssa->allocReg();
        $out  = '  ' . $slot . " = alloca i1\n";
        $out .= '  store i1 0, ptr ' . $slot . "\n";
        $probeL = $this->ssa->allocLabel('gf.probe');
        $capL = $this->ssa->allocLabel('gf.cap');
        $endL = $this->ssa->allocLabel('gf.end');
        $out .= $this->plausiblePtrIr($iw);
        $out .= '  br i1 ' . $this->plausiblePtrReg . ', label %' . $probeL
              . ', label %' . $endL . "\n";
        $out .= $probeL . ":\n";
        $rp = $this->ssa->allocReg();
        $out .= '  ' . $rp . ' = inttoptr i64 ' . $iw . " to ptr\n";
        $tp = $this->ssa->allocReg();
        $out .= '  ' . $tp . ' = getelementptr inbounds i8, ptr ' . $rp . ", i64 -8\n";
        $tw = $this->ssa->allocReg();
        $out .= '  ' . $tw . ' = load i64, ptr ' . $tp . "\n";
        $isCont = $this->magicMatchIr($tw, [\Compile\MemoryAbi::ARRAY_TAG_MAGIC,
            \Compile\MemoryAbi::ARRAY_TAG_ARENA, \Compile\MemoryAbi::ASSOC_TAG_MAGIC,
            \Compile\MemoryAbi::RC_TAG_MAGIC, \Compile\MemoryAbi::ENUM_TAG_MAGIC,
            \Compile\MemoryAbi::STRUCT_TAG_MAGIC]);
        $out .= $this->magicMatchOut;
        $out .= '  br i1 ' . $isCont . ', label %' . $endL . ', label %' . $capL . "\n";
        $out .= $capL . ":\n";
        $cp = $this->ssa->allocReg();
        $out .= '  ' . $cp . ' = getelementptr inbounds i8, ptr ' . $rp . ", i64 -24\n";
        $cw = $this->ssa->allocReg();
        $out .= '  ' . $cw . ' = load i64, ptr ' . $cp . "\n";
        $isGen = $this->ssa->allocReg();
        $out .= '  ' . $isGen . ' = icmp eq i64 ' . $cw . ', '
              . (string)\Compile\MemoryAbi::GENERATOR_TAG_MAGIC . "\n";
        $out .= '  store i1 ' . $isGen . ', ptr ' . $slot . "\n";
        $out .= '  br label %' . $endL . "\n";
        $out .= $endL . ":\n";
        $r = $this->ssa->allocReg();
        $out .= '  ' . $r . ' = load i1, ptr ' . $slot . "\n";
        $this->genFrameReg = $r;
        return $out;
    }

    /**
     * One step of the foreach-over-object iterator protocol.
     *
     * When the iterator's class is known this is just the synthesized method
     * call it always was. When it is an interface ({@see
     * iterNeedsRuntimeClass}), the same step is emitted twice behind the
     * runtime generator test: the frame arm drives the generator directly (the
     * dispatch cannot reach it — a Generator has no class descriptor) and the
     * object arm keeps the virtual call. `valid`/`current`/`key` leave an i64 in
     * lastValue; `rewind`/`next` leave nothing meaningful.
     */
    private function iterProtoStep(bool $dyn, string $iterSlot,
        \Compile\Mir\LoadLocal $iterNode, string $m): string
    {
        $rt = ($m === 'valid') ? \Compile\Mir\Type::bool_()
            : (($m === 'current' || $m === 'key') ? \Compile\Mir\Type::unknown()
                : \Compile\Mir\Type::void());
        if (!$dyn) {
            return $this->emitNode(new \Compile\Mir\MethodCall_($iterNode, $m, [], $rt));
        }
        // The classify is recomputed HERE, per step, rather than hoisted before
        // the loop. A `yield` in the body makes the generator resume switch
        // re-enter mid-loop, so a value defined before the loop does not
        // dominate the blocks the switch lands in — clang rejected the module.
        // The iterator itself lives in a slot (a frame slot inside a generator),
        // so reloading and re-probing is always dominated.
        $out = '';
        $iw = $this->ssa->allocReg();
        $out .= '  ' . $iw . ' = load i64, ptr ' . $iterSlot . "\n";
        $out .= $this->genFrameProbeIr($iw);
        $isGen = $this->genFrameReg;
        $slot = $this->ssa->allocReg();
        $out .= '  ' . $slot . " = alloca i64\n";
        $out .= '  store i64 0, ptr ' . $slot . "\n";
        $genL = $this->ssa->allocLabel('ip.gen');
        $objL = $this->ssa->allocLabel('ip.obj');
        $endL = $this->ssa->allocLabel('ip.end');
        $out .= '  br i1 ' . $isGen . ', label %' . $genL . ', label %' . $objL . "\n";

        $out .= $genL . ":\n";
        $out .= $this->iterFramePtr($iterSlot);
        $g = $this->lastValue;
        if ($m === 'rewind') {
            $out .= $this->genPrimeIfFresh($g);
        } elseif ($m === 'next') {
            $out .= $this->genResumeCall($g);
        } elseif ($m === 'valid') {
            $out .= $this->genPrimeIfFresh($g);
            $out .= $this->genFieldLoad($g, 8);
            $ne = $this->ssa->allocReg();
            $out .= '  ' . $ne . ' = icmp ne i64 ' . $this->lastValue . ", -1\n";
            $z = $this->ssa->allocReg();
            $out .= '  ' . $z . ' = zext i1 ' . $ne . " to i64\n";
            $out .= '  store i64 ' . $z . ', ptr ' . $slot . "\n";
        } else {
            // current@16 / key@24 — both already tagged cells.
            $out .= $this->genFieldLoad($g, ($m === 'current') ? 16 : 24);
            $out .= '  store i64 ' . $this->lastValue . ', ptr ' . $slot . "\n";
        }
        $out .= '  br label %' . $endL . "\n";

        $out .= $objL . ":\n";
        $out .= $this->emitNode(new \Compile\Mir\MethodCall_($iterNode, $m, [], $rt));
        if ($m !== 'rewind' && $m !== 'next') {
            $out .= $this->coerceToI64();
            $out .= '  store i64 ' . $this->lastValue . ', ptr ' . $slot . "\n";
        }
        $out .= '  br label %' . $endL . "\n";

        $out .= $endL . ":\n";
        $r = $this->ssa->allocReg();
        $out .= '  ' . $r . ' = load i64, ptr ' . $slot . "\n";
        $this->lastValue = $r;
        $this->lastValueType = 'i64';
        return $out;
    }

    /** Reload the iterator slot as a frame `ptr`; sets lastValue. */
    private function iterFramePtr(string $iterSlot): string
    {
        $w = $this->ssa->allocReg();
        $out = '  ' . $w . ' = load i64, ptr ' . $iterSlot . "\n";
        $p = $this->ssa->allocReg();
        $out .= '  ' . $p . ' = inttoptr i64 ' . $w . " to ptr\n";
        $this->lastValue = $p;
        $this->lastValueType = 'ptr';
        return $out;
    }

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
        // there so the subject expression is evaluated exactly once. The slot
        // is normally preallocated in the ENTRY block ({@see
        // EmitLlvmLocals::preallocateLocals}) — an alloca left here dominates
        // only the branch the foreach sits in, which breaks the moment a
        // generator's resume switch re-enters the loop past it.
        $iterName = $fe->iterName;
        if ($iterName === '' || !isset($this->locals->slots[$iterName])) {
            if ($iterName === '') {
                $iterName = "@it." . (string)$this->iterCounter;
                $this->iterCounter = $this->iterCounter + 1;
            }
            $iterSlot = $this->ssa->allocReg();
            $this->locals->slots[$iterName] = $iterSlot;
            $out .= '  ' . $iterSlot . " = alloca i64\n";
        }
        $iterSlot = $this->locals->slots[$iterName];
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
        // An interface-typed iterator may be a Generator at runtime, so each
        // protocol step classifies and branches. The body is still emitted
        // exactly once.
        return $out . $this->emitIterProtocolLoop(
            $fe, $iterSlot, $iterName, $iterType,
            $this->iterNeedsRuntimeClass($fe->iterClass));
    }

    /**
     * The Iterator protocol loop over an iterator already stored in `$iterSlot`
     * — `rewind`, then `valid` / `current` / `key` / body / `next`. Shared by
     * the statically-typed object foreach and the erased one, which finds its
     * iterator at runtime ({@see emitForeachErasedIterator}).
     */
    private function emitIterProtocolLoop(\Compile\Mir\Foreach_ $fe, string $iterSlot,
        string $iterName, \Compile\Mir\Type $iterType, bool $dyn): string
    {
        $iterNode = new \Compile\Mir\LoadLocal($iterName, $iterType);
        $out = $this->iterProtoStep($dyn, $iterSlot, $iterNode, 'rewind');

        $condL = $this->ssa->allocLabel('feo.cond');
        $bodyL = $this->ssa->allocLabel('feo.body');
        $stepL = $this->ssa->allocLabel('feo.step');
        $endL  = $this->ssa->allocLabel('feo.end');
        $out .= '  br label %' . $condL . "\n";

        $out .= $condL . ":\n";
        $out .= $this->iterProtoStep($dyn, $iterSlot, $iterNode, 'valid');
        $out .= $this->coerceToI64();
        $v = $this->ssa->allocReg();
        $out .= '  ' . $v . ' = icmp ne i64 ' . $this->lastValue . ", 0\n";
        $out .= '  br i1 ' . $v . ', label %' . $bodyL . ', label %' . $endL . "\n";

        $out .= $bodyL . ":\n";
        $out .= $this->iterProtoStep($dyn, $iterSlot, $iterNode, 'current');
        $out .= $this->coerceToI64();
        $out .= '  store i64 ' . $this->lastValue . ', ptr ' . $this->locals->slots[$fe->valueVar] . "\n";
        if ($fe->keyVar !== null) {
            $out .= $this->iterProtoStep($dyn, $iterSlot, $iterNode, 'key');
            $out .= $this->coerceToI64();
            $out .= '  store i64 ' . $this->lastValue . ', ptr ' . $this->locals->slots[$fe->keyVar] . "\n";
        }
        $this->cf->enterLoop($endL, $stepL);
        $out .= $this->emitNode($fe->body);
        $this->cf->leave();
        $out .= '  br label %' . $stepL . "\n";

        $out .= $stepL . ":\n";
        $out .= $this->iterProtoStep($dyn, $iterSlot, $iterNode, 'next');
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

    /**
     * Does this conditional arm already carry a +1 that transfers to the
     * conditional's result? Only a DEFINITE owned producer counts — a literal
     * (immortal), a fresh allocation / concat, a method / static / closure call,
     * a user free-function call (a builtin may hand back a borrowed element, so
     * only a known signature proves it), a null arm (there is nothing to own),
     * or a nested conditional the same contract already normalized.
     *
     * Conservative BY DESIGN, and in one direction only: a borrow mistaken for
     * fresh corrupts (the release side frees what nobody owned), a fresh value
     * mistaken for a borrow leaks one reference.
     *
     * The free-function case follows each flavor's ESTABLISHED convention: a
     * string-returning builtin is +1 ({@see EmitLlvm::isFreshStringTemp} already
     * releases its temp, and {@see InsertMemoryOps::isOwnedObj} owns it —
     * substr / strtolower / str_repeat), while an obj/array builtin may hand
     * back a borrowed element (`current()`), so only a known user signature
     * proves ownership there ({@see EmitLlvm::freshRcArgFlavor} draws the same
     * line). Getting this wrong cost a measured leak: str_repeat read as
     * borrowed retained a temp nobody else owned.
     */
    private function armIsFresh(Node $arm, string $flavor): bool
    {
        if ($arm->type->kind === Type::KIND_NULL) { return true; }
        $k = $arm->kind;
        if ($k === Node::KIND_STRING_CONST || $k === Node::KIND_CONCAT
            || $k === Node::KIND_ARRAY_LIT || $k === Node::KIND_SPREAD
            || $k === Node::KIND_NEW_OBJ || $k === Node::KIND_CLONE
            || $k === Node::KIND_METHOD_CALL || $k === Node::KIND_STATIC_CALL
            || $k === Node::KIND_INVOKE) {
            return true;
        }
        if ($k === Node::KIND_CALL) {
            $fn = $arm->function;
            if ($this->sigs->returnsByRef[$fn] ?? false) { return false; }
            if ($flavor === 'str') { return true; }
            return isset($this->sigs->paramTypes[$fn]);
        }
        return $this->condOwnsResult($arm);
    }

    /**
     * A conditional (ternary / `?:` / `??` / match) yields an OWNED (+1) value of
     * its RESULT type from EVERY arm, so a borrowed arm — an alias, a property /
     * element read, a param — is retained here. The contract, and which shapes
     * qualify, live in {@see CondOwn} / {@see EmitLlvm::condOwnsResult}; the
     * consumers that release it are {@see EmitLlvm::isFreshStringTemp},
     * {@see EmitLlvm::freshRcArgFlavor}, {@see EmitLlvmCalls::emitDiscardedCallRelease}
     * and {@see InsertMemoryOps::isOwnedObj}.
     *
     * Without it `$out = $out === '' ? $s : ($out . ',' . $s);` stored $s's buffer
     * BORROWED: the next iteration's `$s = trim(...)` freed it and the allocator
     * handed the same block back, so the accumulated value silently became the
     * newest element repeated. Assignment retains a bare alias ($x = $s) but never
     * looked inside a conditional, and the mixed shape — one owned arm, one
     * borrowed — is the `string|false` idiom, so it is everywhere.
     *
     * This is the POST-BOX half: the retain runs on the arm's final carrier, at
     * the depth the result's flavor will drop ({@see EmitLlvm::condFlavor} feeds
     * both sides), which is why an arm of a different array element type is not
     * coverable at all.
     */
    private function armRetainPostBox(Node $res, Node $arm, string $i64reg): string
    {
        if (!$this->condOwnsResult($res)) { return ''; }
        $flavor = $this->condFlavor($res->type);
        if ($flavor === '' || $flavor === 'cell') { return ''; }
        if ($this->armIsFresh($arm, $flavor)) { return ''; }
        return $this->rcRetainReg($i64reg, $flavor);
    }

    /**
     * armRetain on the value currently in lastValue, leaving lastValue and its
     * type untouched — for the `??` paths that hand their arm straight back
     * without going through the i64 carrier.
     */
    private function armRetainLast(Node $res, Node $arm): string
    {
        if (!$this->condOwnsResult($res)) { return ''; }
        $flavor = $this->condFlavor($res->type);
        if ($flavor === '') { return ''; }
        if ($flavor === 'cell') { return $this->armRetainPreBox($res, $arm); }
        if ($this->armIsFresh($arm, $flavor)) { return ''; }
        $sv = $this->lastValue;
        $st = $this->lastValueType;
        $out = $this->coerceToI64();
        $out .= $this->rcRetainReg($this->lastValue, $flavor);
        $this->lastValue = $sv;
        $this->lastValueType = $st;
        return $out;
    }

    /**
     * The PRE-BOX half, for a CELL-typed conditional: a cell owns its payload by
     * POINTER, so the co-owner retain must see the raw value — after boxToCell a
     * concrete array has been rebuilt and a scalar is a tag. Same order
     * {@see EmitLlvmModule::emitReturn} uses for a `: mixed` return.
     */
    private function armRetainPreBox(Node $res, Node $arm): string
    {
        if (!$this->condOwnsResult($res)) { return ''; }
        if ($this->condFlavor($res->type) !== 'cell') { return ''; }
        if ($this->armIsFresh($arm, 'cell')) { return ''; }
        return $this->retainCellPayload($arm);
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
            $out .= $this->truthinessOf($t->cond->type, $t->cond);
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
        $thenArm = $t->then;
        if ($thenArm === null) { $thenArm = $t->cond; }
        if ($t->then !== null) {
            $out .= $this->emitNode($t->then);
            if ($wantCell) {
                $out .= $this->armRetainPreBox($n, $thenArm);
                $out .= $this->boxToCell($t->then->type);
            } else {
                $out .= $this->coerceToI64();
            }
            $thenVal = $this->lastValue;
        } elseif ($wantCell) {
            $this->lastValue = $rawCond;
            $this->lastValueType = 'i64';
            $out .= $this->armRetainPreBox($n, $thenArm);
            $out .= $this->boxToCell($t->cond->type);
            $thenVal = $this->lastValue;
        } else {
            $thenVal = $rawCond;
        }
        $out .= $this->armRetainPostBox($n, $thenArm, $thenVal);
        $out .= '  store i64 ' . $thenVal . ', ptr ' . $res . "\n";
        $out .= '  br label %' . $endLabel . "\n";
        $out .= $elseLabel . ":\n";
        $out .= $this->emitNode($t->else_);
        if ($wantCell) {
            $out .= $this->armRetainPreBox($n, $t->else_);
            $out .= $this->boxToCell($t->else_->type);
        } else {
            $out .= $this->coerceToI64();
        }
        $out .= $this->armRetainPostBox($n, $t->else_, $this->lastValue);
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

    /** Does this foreach's body `unset()` an element of the very local it walks?
     *  Syntactic and deliberately narrow — the snapshot copy it triggers costs
     *  an allocation, so only the shape that actually needs it pays. */
    private function foreachBodyUnsetsBase(Foreach_ $fe): bool
    {
        if ($fe->array->kind !== Node::KIND_LOAD_LOCAL) { return false; }
        return $this->nodeUnsetsLocalElem($fe->body, $fe->array->name);
    }

    private function nodeUnsetsLocalElem(Node $n, string $name): bool
    {
        if ($n->kind === Node::KIND_UNSET) {
            foreach ($n->targets as $t) {
                if ($t->kind === Node::KIND_ARRAY_ACCESS
                    && $t->array->kind === Node::KIND_LOAD_LOCAL
                    && $t->array->name === $name) {
                    return true;
                }
            }
        }
        foreach (\Compile\Mir\Walk::children($n) as $c) {
            if ($this->nodeUnsetsLocalElem($c, $name)) { return true; }
        }
        return false;
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
        // An ERASED base (`mixed` cell, or an element read out of an untyped
        // array) is NOT known to be an array. Stripping the tag unconditionally
        // walked whatever it held: a cell carrying an OBJECT was read as an array
        // header. Classify at runtime and fall back to the empty array — see
        // {@see arrayPtrOrEmptyIr} for the semantics this does and does not give.
        $bk = $fe->array->type->kind;
        $dynEnd = '';
        $dynGen = ($bk === Type::KIND_CELL || $bk === Type::KIND_UNKNOWN)
            && !$this->foreachBodyYields($fe->body);
        if ($dynGen) {
            $out .= $this->coerceToI64();
            $word = $this->lastValue;
            // An erased carrier can also be a GENERATOR, and classifying only
            // "array or not" answered the empty array for one — zero iterations,
            // silently. Every lazy producer arrives this way: a `callable`/
            // `iterable` param, a closure invoke (dynamic ⇒ cell), a method
            // declared `: \Traversable`. symfony's
            // `calculateColumnsWidth(iterable $groups)` is exactly that, so the
            // Table measured no columns and drew its borders around nothing.
            //
            // Probe first, and drive the real generator protocol when it is one.
            // The body is emitted in both arms; that cost is paid only by an
            // erased base, and only one arm ever runs.
            //
            // ⚠ NOT when the body itself yields: emitting it twice would emit
            // twice the yields, and the resume switch is built from the SOURCE
            // yield count ({@see EmitLlvmGenerator::emitGenerator}) — the second
            // copy's `gen.resume.N` labels would have no switch case. Such a
            // foreach keeps the array-only classification it has always had.
            // ⚠ The carrier may be a BOXED cell — a generator handed to a
            // `mixed`/`iterable` param is tagged on the way in, and every probe
            // below starts at {@see plausiblePtrIr}, which rejects a tagged word
            // outright. The classify then always answered "not a generator",
            // fell into the array arm and iterated ZERO times — silently.
            // symfony's `calculateColumnsWidth(iterable $groups)` never ran, so
            // every Table column measured 0. Strip the NaN tag first; a raw word
            // is left exactly as it is.
            $out .= $this->untagCarrierIr($word);
            $word = $this->lastValue;
            $out .= $this->genFrameProbeIr($word);
            $isGen = $this->genFrameReg;
            $gArm = $this->ssa->allocLabel('fe.dyn.gen');
            $oChk = $this->ssa->allocLabel('fe.dyn.ochk');
            $oArm = $this->ssa->allocLabel('fe.dyn.obj');
            $aArm = $this->ssa->allocLabel('fe.dyn.arr');
            $dynEnd = $this->ssa->allocLabel('fe.dyn.end');
            $out .= '  br i1 ' . $isGen . ', label %' . $gArm . ', label %' . $oChk . "\n";
            $out .= $gArm . ":\n";
            $gp = $this->ssa->allocReg();
            $out .= '  ' . $gp . ' = inttoptr i64 ' . $word . " to ptr\n";
            // The subject temp is released by the erased path's own bookkeeping,
            // so this arm must NOT also drop the frame.
            $out .= $this->emitForeachGeneratorFrom($fe, $gp, false);
            $out .= '  br label %' . $dynEnd . "\n";
            // An erased carrier can also be a plain TRAVERSABLE OBJECT, and
            // "array or generator" answered the empty array for one. symfony's
            // `calculateColumnsWidth(iterable $groups)` is handed a `TableRows`
            // (an IteratorAggregate), so the width loop ran ZERO times and every
            // column measured 0 — the Table drew ` -- -- -- ` around correctly
            // rendered rows.
            $out .= $oChk . ":\n";
            if ($this->hasTraversableClasses()) {
                $out .= $this->objectProbeIr($word);
                $out .= '  br i1 ' . $this->objectProbeReg . ', label %' . $oArm
                      . ', label %' . $aArm . "\n";
                $out .= $oArm . ":\n";
                $out .= $this->emitForeachErasedIterator($fe, $word);
                $out .= '  br label %' . $dynEnd . "\n";
            } else {
                $out .= '  br label %' . $aArm . "\n";
            }
            $out .= $aArm . ":\n";
            $out .= $this->arrayPtrOrEmptyIr($word);
            $this->lastValue = $this->arrayPtrReg;
            $this->lastValueType = 'ptr';
        } elseif ($bk === Type::KIND_CELL || $bk === Type::KIND_UNKNOWN) {
            $out .= $this->coerceToI64();
            $out .= $this->arrayPtrOrEmptyIr($this->lastValue);
            $this->lastValue = $this->arrayPtrReg;
            $this->lastValueType = 'ptr';
        } else {
            $out .= $this->coerceToPtr();
        }
        $arr = $this->lastValue;
        // `foreach ($a as $k => $v) { … unset($a[$k]); … }` — PHP iterates a
        // SNAPSHOT of a by-value foreach, so the deletions do not disturb the
        // walk. That is not free here: an unset on a packed buffer promotes it
        // to the hashed layout, which RELOCATES, and the loop would keep
        // walking the freed base. Copy up front for this shape only; the copy
        // is a bounded leak (conservative direction — never free a buffer the
        // body may still hand out).
        if ($this->foreachBodyUnsetsBase($fe)) {
            $snap = $this->ssa->allocReg();
            $out .= '  ' . $snap . ' = call ptr @__mir_array_copy(ptr ' . $arr . ")\n";
            $arr = $snap;
        }
        // Empty vec/assoc literals lower to a null ptr; reading the length
        // word from null faults. Redirect a null base to a shared zero word
        // so `len` reads 0 and the loop body is skipped entirely. A non-array
        // (erased) base is handled inside live_len (tag guard → len 0).
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
        // ⚠ The value word is NOT decoded by the array's element hint, on any
        // channel. The hint says what the elements ARE at runtime, but every
        // STATIC type downstream still says otherwise, and the two desynchronise
        // the moment the value is stored back: `uasort` decorates
        // `foreach ($arr as $k => $v)` into records and rebuilds $arr from them,
        // so a decoded $v lands as a tagged word inside the caller's
        // `assoc[string]`, which print_r then deref'd — natsort SIGSEGV,
        // measured twice, with and without a store-side re-encode. The decode
        // only becomes sound once the erased element CHANNEL is retyped cell in
        // InferTypes: that is the rest of this epic.
        //
        // The opposite direction IS sound and is done: when the static element
        // type says STRING the value slot is read as a raw pointer everywhere, so
        // a slot that actually holds a boxed cell has to be stripped to its
        // payload. That claim is not always true — `foreach (array_values(
        // array_keys($assoc)) as $n) { str_contains($n, …) }` through a `string[]`
        // param walks cells — and it hands nothing downstream that the static
        // type did not already promise. {@see EmitLlvmArrays::emitArrayAccessUnified}
        $fel = $fe->array->type->element ?? null;
        if ($fel !== null
            && ($fel->kind === Type::KIND_STRING || $fel->kind === Type::KIND_OBJ)) {
            $this->rt->needsElemUntag = true;
            $eu = $this->ssa->allocReg();
            $out .= '  ' . $eu . ' = call i64 @__mir_elem_untag(ptr ' . $arr
                  . ', i64 ' . $ev . ")\n";
            $ev = $eu;
        }
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
        // Rejoin the generator arm of the erased-base classify above.
        if ($dynEnd !== '') {
            $out .= '  br label %' . $dynEnd . "\n";
            $out .= $dynEnd . ":\n";
            $this->lastValue = '0';
            $this->lastValueType = 'i64';
        }
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
            if ($wantCell) {
                $out .= $this->armRetainPreBox($n, $arm->body);
                $out .= $this->boxToCell($arm->body->type);
            } else {
                $out .= $this->coerceToI64();
            }
            $out .= $this->armRetainPostBox($n, $arm->body, $this->lastValue);
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
        return $out . $this->truthinessOf($cond->type, $cond);
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
