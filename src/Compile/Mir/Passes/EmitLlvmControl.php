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
        // The frame owns `current` and drops it at the next yield, so the loop
        // variable takes a +1 of its own — php's `$v` is a copy that outlives
        // the step and the loop. A loop that co-owns gives back the previous
        // iteration's; one that does not ({@see foreachValueOwns}) keeps the
        // +1 rather than hold a word the next resume frees.
        if ($this->foreachValueOwns($fe)) {
            $out .= $this->foreachOwnedRebind($fe, $cur, true);
        } else {
            $gf = ($gelem->kind === Type::KIND_CELL || $gelem->kind === Type::KIND_UNKNOWN)
                ? 'cell' : $this->discardReleaseFlavor($gelem);
            if ($gf !== '') { $out .= $this->rcRetainReg($cur, $gf); }
            $out .= $this->foreachPrevDrop($fe, false);
        }
        $out .= '  store i64 ' . $cur . ', ptr ' . $this->locals->slots[$fe->valueVar] . "\n";
        if ($fe->keyVar !== null) {
            $out .= $this->genFieldLoad($g, 24);
            $kw = $this->lastValue;
            if ($fe->ownKey) { $out .= $this->rcRetainReg($kw, 'cell'); }
            $out .= $this->foreachPrevDrop($fe, true);
            $out .= '  store i64 ' . $kw . ', ptr ' . $this->locals->slots[$fe->keyVar] . "\n";
        }
        $out .= $this->emitForeachBodyArm($fe, $endLabel, $stepLabel, true);

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
     * A word that is a RAW object pointer → the equivalent object cell; anything
     * else (already NaN-boxed, an array, a string, a small int) passes through.
     * lastValue ← the normalised word.
     *
     * Used where a value has to be self-describing because a sibling code path
     * produces cells there ({@see iterProtoStep}). Only RC_TAG_MAGIC counts as
     * an object — an array carries ARRAY_TAG_MAGIC / ARRAY_TAG_ARENA / ASSOC,
     * so the probe cannot mistake one for the other.
     */
    private function rawObjToCellIr(string $w): string
    {
        $this->rt->needsTagged = true;
        $slot = $this->ssa->allocReg();
        $out  = '  ' . $slot . " = alloca i64\n";
        $out .= '  store i64 ' . $w . ', ptr ' . $slot . "\n";
        $isBox = $this->ssa->allocReg();
        $out .= '  ' . $isBox . ' = icmp ugt i64 ' . $w . ", -4503599627370496\n";
        $rawL = $this->ssa->allocLabel('r2c.raw');
        $endL = $this->ssa->allocLabel('r2c.end');
        $out .= '  br i1 ' . $isBox . ', label %' . $endL . ', label %' . $rawL . "\n";
        $out .= $rawL . ":\n";
        $out .= $this->objectProbeIr($w);
        $boxL = $this->ssa->allocLabel('r2c.box');
        $out .= '  br i1 ' . $this->objectProbeReg . ', label %' . $boxL
              . ', label %' . $endL . "\n";
        $out .= $boxL . ":\n";
        $p = $this->ssa->allocReg();
        $out .= '  ' . $p . ' = inttoptr i64 ' . $w . " to ptr\n";
        $b = $this->ssa->allocReg();
        $out .= '  ' . $b . ' = call i64 @__manticore_box_object(ptr ' . $p . ")\n";
        $out .= '  store i64 ' . $b . ', ptr ' . $slot . "\n";
        $out .= '  br label %' . $endL . "\n";
        $out .= $endL . ":\n";
        $r = $this->ssa->allocReg();
        $out .= '  ' . $r . ' = load i64, ptr ' . $slot . "\n";
        $this->lastValue = $r;
        $this->lastValueType = 'i64';
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
        $nsh = \count($this->feShared);
        $sh = ($nsh > 0 && $this->feShared[$nsh - 1]->fe === $fe) ? $this->feShared[$nsh - 1] : null;
        $shared = $sh !== null && $sh->aggOwnSlot !== '';
        $subjSlot = $this->ssa->allocReg();
        $iterSlot = $shared ? $sh->aggIterSlot : $this->ssa->allocReg();
        $this->locals->slots[$subjName] = $subjSlot;
        $this->locals->slots[$iterName] = $iterSlot;
        $out  = '  ' . $subjSlot . " = alloca i64\n";
        if (!$shared) { $out .= '  ' . $iterSlot . " = alloca i64\n"; }
        $out .= '  store i64 ' . $word . ', ptr ' . $subjSlot . "\n";
        $out .= '  store i64 ' . $word . ', ptr ' . $iterSlot . "\n";
        // Whether the slot holds getIterator()'s result (+1, the loop's to give
        // back) or the subject itself (borrowed).
        $ownSlot = $shared ? $sh->aggOwnSlot : $this->ssa->allocReg();
        if (!$shared) { $out .= '  ' . $ownSlot . " = alloca i1\n"; }
        $out .= '  store i1 0, ptr ' . $ownSlot . "\n";
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
        $out .= '  store i1 1, ptr ' . $ownSlot . "\n";
        $out .= '  br label %' . $joinL . "\n";
        $out .= $joinL . ":\n";
        // The getIterator() result was never given back — not even at the end
        // label — so every erased foreach over an IteratorAggregate leaked it,
        // and with it the subject (php-cs-fixer: a Tokens and all its tokens
        // per erased `foreach ($tokens …)`).
        $this->cf->pushAggIter($iterSlot, true, $ownSlot);
        $out .= $this->emitIterProtocolLoop(
            $fe, $iterSlot, $iterName, \Compile\Mir\Type::obj('Iterator'), true);
        $this->cf->popAggIter();
        $out .= $this->releaseAggIterSlot($iterSlot, true, $ownSlot);
        $this->lastValue = '0';
        $this->lastValueType = 'i64';
        return $out;
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
        // ⚠ The two arms below must AGREE on representation: the generator arm
        // reads current@16 / key@24, which are already tagged CELLS. Asking the
        // virtual dispatch for `unknown` left the object arm returning its
        // callee's raw word — an object pointer, or a raw string pointer for a
        // `key(): string` — and the merged value was then read on the tag that
        // was never applied. `foreach ($sxe as $name => $node)` printed the tag
        // name as 2.16E-314 and `$b['id']` inside the loop reached the array
        // subscript instead of offsetGet.
        //
        // Asking for a CELL turns on emitVirtualDispatch's per-arm boxing, which
        // boxes each candidate by its OWN declared return type — the general fix,
        // and the only one that covers a string key as well as an object value.
        if ($m === 'current' || $m === 'key') {
            $rt = \Compile\Mir\Type::cell();
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
            // current@16 / key@24 — both already tagged cells. `current` is the
            // frame's own; the object arm's method call answers a +1, and so
            // must this one.
            $out .= $this->genFieldLoad($g, ($m === 'current') ? 16 : 24);
            if ($m === 'current') { $out .= $this->rcRetainReg($this->lastValue, 'cell'); }
            $out .= '  store i64 ' . $this->lastValue . ', ptr ' . $slot . "\n";
        }
        $out .= '  br label %' . $endL . "\n";

        $out .= $objL . ":\n";
        $out .= $this->emitNode(new \Compile\Mir\MethodCall_($iterNode, $m, [], $rt));
        if ($m !== 'rewind' && $m !== 'next') {
            $out .= $this->coerceToI64();
            // A candidate the dispatch could not resolve statically still hands
            // back a raw word; normalise an object pointer so it agrees with the
            // per-arm boxing the cell-typed call above asks for.
            if ($m === 'current' || $m === 'key') {
                $out .= $this->rawObjToCellIr($this->lastValue);
            }
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
        $dyn = $this->iterNeedsRuntimeClass($fe->iterClass);
        // The iterator `getIterator()` handed back is the loop's own (+1): give
        // it back when the loop ends — it held the subject, and with it every
        // element (a SplFixedArray's). A Generator frame is given back the same
        // way: the object release routes on the header, and the frame lets go
        // of what it holds ({@see EmitLlvmGenerator}). A `return` / `break N` /
        // `continue N` out of the body branches past the end label, so it
        // releases the same slot on its way out ({@see ControlFlow::aggItersLeftBy}).
        $owns = $fe->iterAggregate
            && ($fe->iterClass === 'Generator' || $dyn || isset($this->classes[$fe->iterClass]));
        if ($owns) { $this->cf->pushAggIter($iterSlot, $dyn); }
        $out .= $this->emitIterProtocolLoop($fe, $iterSlot, $iterName, $iterType, $dyn);
        if ($owns) {
            $this->cf->popAggIter();
            $out .= $this->releaseAggIterSlot($iterSlot, $dyn);
            $this->lastValue = '0';
            $this->lastValueType = 'i64';
        }
        return $out;
    }

    /**
     * Give back an aggregate foreach's iterator and clear its slot. `$flag`,
     * when set, is an i1 slot saying whether the loop owns the iterator at all
     * — the erased foreach drives a subject that IS an Iterator (borrowed) or
     * one it got from getIterator() (owned) through the same slot.
     */
    /**
     * The release flavor of a foreach's iterable when the loop owns it: a fresh
     * +1 array ({@see \Compile\Mir\Ownership::tempArgFlavor}) that nothing
     * past the loop can still borrow from — a by-value walk whose value var the
     * flow co-owns (or holds a non-rc scalar) and whose keys are ints. ''
     * otherwise: a borrowed element or string key read after the loop would
     * die with the array.
     */
    private function ownedIterableFlavor(\Compile\Mir\Foreach_ $fe): string
    {
        if ($fe->byRef || $fe->genSlotBase >= 0) { return ''; }
        $at = $fe->array->type;
        if (!$at->isArray()) { return ''; }
        $el = $at->element;
        if (!$fe->ownCoOwn && ($el === null || $el->kind === Type::KIND_UNKNOWN
            || $el->kind === Type::KIND_CELL || $this->own->flavorOf($el) > 0)) { return ''; }
        if ($fe->keyVar !== null && !$at->isVec()) { return ''; }
        // A literal holds one reference on each array element (a fresh one
        // transferred, a borrowed one retained); `vecbuf` is the ARGUMENT
        // answer, where the call gives those back. Here nobody else does: the
        // loop is the literal's sole owner, as a local slot is, so it drops
        // them by the local slot's element walk ({@see rcReleaseFlavorPlain}).
        if ($fe->array->kind === Node::KIND_ARRAY_LIT && $el !== null && $el->isArray()) {
            return $this->nestedArrFlavor($el, $at->isAssoc() ? 'assoc' : 'vec');
        }
        return $this->freshRcArgFlavor($fe->array);
    }

    private function releaseAggIterSlot(string $iterSlot, bool $dyn, string $flag = '', string $flavor = ''): string
    {
        $out = '';
        if ($flavor !== '') {
            // A fresh array iterable the loop owns ({@see ownedIterableFlavor}).
            $a = $this->ssa->allocReg();
            $out .= '  ' . $a . ' = load i64, ptr ' . $iterSlot . "\n";
            $out .= $this->rcReleaseReg($a, $flavor);
            $out .= '  store i64 0, ptr ' . $iterSlot . "\n";
            return $out;
        }
        $skipL = '';
        if ($flag !== '') {
            $fv = $this->ssa->allocReg();
            $out .= '  ' . $fv . ' = load i1, ptr ' . $flag . "\n";
            $relL = $this->ssa->allocLabel('aggit.rel');
            $skipL = $this->ssa->allocLabel('aggit.skip');
            $out .= '  br i1 ' . $fv . ', label %' . $relL . ', label %' . $skipL . "\n";
            $out .= $relL . ":\n";
            $out .= '  store i1 0, ptr ' . $flag . "\n";
        }
        $it = $this->ssa->allocReg();
        $out .= '  ' . $it . ' = load i64, ptr ' . $iterSlot . "\n";
        // An object or — `getIterator(): Iterator` may hand one back at run
        // time — a Generator frame: `__mir_rc_release` routes on the header.
        $out .= $this->rcReleaseReg($it, 'obj');
        $out .= '  store i64 0, ptr ' . $iterSlot . "\n";
        if ($skipL !== '') {
            $out .= '  br label %' . $skipL . "\n" . $skipL . ":\n";
        }
        return $out;
    }

    /** Release the open aggregate iterators a jump to loop `$level` leaves (0: a return). */
    private function releaseAggItersLeftBy(int $level): string
    {
        $out = '';
        foreach ($this->cf->aggItersLeftBy($level) as $i) {
            $out .= $this->releaseAggIterSlot($this->cf->aggIterSlot($i), $this->cf->aggIterDyn($i),
                $this->cf->aggIterFlag($i), $this->cf->aggIterFlavor($i));
        }
        return $out;
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
        $cur = $this->lastValue;
        // `current()` is +1 on every arm — a method's return convention, and
        // the generator arm retains to match — so a co-owning loop variable
        // only gives back the previous iteration's.
        if ($this->foreachValueOwns($fe)) {
            $out .= $this->foreachOwnedRebind($fe, $cur, false);
        } else {
            $out .= $this->foreachPrevDrop($fe, false);
        }
        $out .= '  store i64 ' . $cur . ', ptr ' . $this->locals->slots[$fe->valueVar] . "\n";
        if ($fe->keyVar !== null) {
            $out .= $this->iterProtoStep($dyn, $iterSlot, $iterNode, 'key');
            $out .= $this->coerceToI64();
            $kw = $this->lastValue;
            $out .= $this->foreachPrevDrop($fe, true);
            $out .= '  store i64 ' . $kw . ', ptr ' . $this->locals->slots[$fe->keyVar] . "\n";
        }
        $out .= $this->emitForeachBodyArm($fe, $endL, $stepL, true);

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
            || $k === Node::KIND_INVOKE || \Compile\Mir\BitOp::mintsFresh($arm)) {
            return true;
        }
        if ($k === Node::KIND_CALL) {
            $fn = $arm->function;
            if ($this->sigs->returnsByRef[$fn] ?? false) { return false; }
            if ($flavor === 'str') { return true; }
            return isset($this->sigs->paramTypes[$fn]);
        }
        // `$s[$i]` mints a buffer — the same read {@see EmitLlvm::isFreshStringTemp}
        // has always released. Missing it here retained an already-owned +1.
        if ($this->isStrCharRead($arm)) { return true; }
        // `(string)$x` answers exactly as its consumers read it ({@see
        // EmitLlvm::isFreshStringTemp}'s cast arm): every operand but a string
        // mints or retains a +1, a string passes its own ownership through.
        // Missing it here retained the +1 again — `isset($u['host']) ?
        // (string)$u['host'] : ''` leaked the host once per call.
        if ($k === Node::KIND_CAST && $arm->type->kind === Type::KIND_STRING) {
            return $this->isFreshStringTemp($arm);
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
        // {@see armCoerce} rebuilt it: a fresh +1 already.
        if (\Compile\Mir\Ownership::needsCellify($res->type, $arm->type)) { return ''; }
        $flavor = $this->condResFlavor($res);
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
        $flavor = $this->condResFlavor($res);
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
        if ($this->condResFlavor($res) !== 'cell') { return ''; }
        if ($this->armIsFresh($arm, 'cell')) { return ''; }
        return $this->retainCellPayload($arm);
    }

    /**
     * An arm of a non-cell conditional, onto the i64 carrier. A concrete-element
     * array arm under a CELL-element result (the arms' element join,
     * {@see InferNodes::armArrayJoin}) is rebuilt into a fresh cell buffer first,
     * so the buffer IS what the result's type claims; the rebuild is the arm's
     * +1 ({@see armRetainPostBox} skips it). `lastValue` holds the arm's value.
     */
    private function armCoerce(Node $res, Node $arm): string
    {
        $out = '';
        if (\Compile\Mir\Ownership::needsCellify($res->type, $arm->type)) {
            $out .= $this->emitCellifyArrayRaw($arm->type->element, $this->cellifySourceFlavor($arm));
        }
        return $out . $this->coerceToI64();
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
                $out .= $this->boxToCell($t->then->type, $t->then);
            } else {
                $out .= $this->armCoerce($n, $t->then);
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
            $out .= $this->boxToCell($t->else_->type, $t->else_);
        } else {
            $out .= $this->armCoerce($n, $t->else_);
        }
        $elseVal = $this->lastValue;
        $out .= $this->armRetainPostBox($n, $t->else_, $elseVal);
        $out .= '  store i64 ' . $elseVal . ', ptr ' . $res . "\n";
        $out .= '  br label %' . $endLabel . "\n";
        $out .= $endLabel . ":\n";
        $loaded = $this->ssa->allocReg();
        $out .= '  ' . $loaded . ' = load i64, ptr ' . $res . "\n";
        if ($wantCell) { $this->joinCellProvenance([$thenVal, $elseVal], $loaded); }
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

    /** Does this foreach's body MUTATE the very local it walks — `unset()`, an
     *  element store, an append, a by-ref builtin, a reference taken into it?
     *  Every one of those can RELOCATE the entry buffer under the walk (a grow
     *  reallocs, an unset promotes packed → hashed, a full buffer with holes
     *  compacts), and php's by-value foreach iterates a SNAPSHOT anyway.
     *  Syntactic and deliberately narrow — the copy it triggers costs an
     *  allocation, so only the shape that actually needs it pays. A BY-REF
     *  foreach writes back through the element addresses, so it must keep
     *  walking the real buffer and never takes the copy. */
    private function foreachBodyMutatesBase(Foreach_ $fe): bool
    {
        if ($fe->byRef) { return false; }
        if ($fe->array->kind !== Node::KIND_LOAD_LOCAL) { return false; }
        return $this->nodeMutatesLocalElem($fe->body, $fe->array->name);
    }

    /** The mutation shapes of {@see EmitLlvmMemory::collectMutatedVecs}, asked
     *  of ONE name: the two scans must agree about what "mutates an array" is. */
    private function nodeMutatesLocalElem(Node $n, string $name): bool
    {
        if ($n->kind === Node::KIND_UNSET) {
            foreach ($n->targets as $t) {
                if ($this->elemRootIs($t, $name)) { return true; }
            }
        }
        if ($n->kind === Node::KIND_STORE_ELEMENT && $this->elemRootIs($n->array, $name, true)) {
            return true;
        }
        if ($n->kind === Node::KIND_REF_ADDR && $this->elemRootIs($n->lvalue, $name)) {
            return true;
        }
        if ($n->kind === Node::KIND_CALL && \count($n->args) > 0
            && $this->mutatesArg0($n->function)
            && $this->elemRootIs($n->args[0], $name, true)) {
            return true;
        }
        foreach (\Compile\Mir\Walk::children($n) as $c) {
            if ($this->nodeMutatesLocalElem($c, $name)) { return true; }
        }
        return false;
    }

    /** Is `$n` an element chain (or, with `$bare`, the local itself) rooted at
     *  the local `$name`? `$a[0][1]` roots at `$a` — a nested store relocates
     *  the outer buffer too. */
    private function elemRootIs(Node $n, string $name, bool $bare = false): bool
    {
        $base = $n;
        $depth = 0;
        while ($base->kind === Node::KIND_ARRAY_ACCESS) { $base = $base->array; $depth++; }
        if (!$bare && $depth === 0) { return false; }
        return $base->kind === Node::KIND_LOAD_LOCAL && $base->name === $name;
    }


    /**
     * A co-owning iterator loop binding `$cur`: take its +1 when the step
     * handed out a borrow (`$retain`), then drop the previous iteration's —
     * retain first, so a value met twice goes 1 → 2 → 1.
     */
    private function foreachOwnedRebind(Foreach_ $fe, string $cur, bool $retain): string
    {
        $fl = $this->rcReleaseFlavor($fe->ownBind ?? $this->frame->ownLocals[$fe->valueVar]);
        if ($fl === '') { return ''; }
        $out = $retain ? $this->rcRetainReg($cur, $fl) : '';
        return $out . $this->foreachPrevDrop($fe, false);
    }

    /**
     * The emitter half of foreach-value co-ownership — it asks the SAME
     * predicate the pass does, so the two cannot decide differently about a
     * name ({@see InsertMemoryOps::foreachValueCoOwns}).
     */
    private function foreachValueOwns(Foreach_ $fe): bool
    {
        if (\Compile\Debug::$feOnly !== ''
            && !\str_contains($this->frame->name, \Compile\Debug::$feOnly)) { return false; }
        if (!InsertMemoryOps::foreachValueCoOwns($fe, $this->enums, $this->classes)) { return false; }
        // ★★★ THE PASS DECIDES; THE EMITTER OBEYS. `ownCoOwn` + `ownLocals` ARE
        // that decision, already transported through the IR and collected per
        // function ({@see EmitLlvmMemory::initOwnSlots}) — so the retain here
        // and the pass's drops cannot disagree about a name.
        //
        // This used to RE-DERIVE the answer from `frame->body`, cached on that
        // body's identity. `EmitLlvmModule` NULLS `frame->body` at five points
        // during emission, and on every one of them the cache silently kept the
        // PREVIOUS function's veto set: the emitter then answered for the wrong
        // function, took a +1 the pass had planted no release for (or skipped
        // one it had), and the resulting over-release was a wild write with no
        // rc underflow to catch it. Every `InferTypes` method family reproduced
        // it independently, which is what a per-function bookkeeping bug looks
        // like and what a per-site one never does.
        if (!$fe->ownCoOwn) { return false; }
        return isset($this->frame->ownLocals[$fe->valueVar]);
    }

    /**
     * {@see OwnershipFlow}'s drop of what the value (`$key` false) or key slot
     * still holds at a binding — present only where the flow owns it at the
     * loop head.
     */
    private function foreachPrevDrop(Foreach_ $fe, bool $key): string
    {
        $mo = $key ? $fe->ownDropKey : $fe->ownDropValue;
        if ($mo === null) { return ''; }
        $slot = $this->ownOpSlot($mo);
        return $slot === '' ? '' : $this->ownDropIr($slot, $mo);
    }

    /**
     * A bare `array` parameter read as the foreach subject, in a body that never
     * rebinds it: php checked the hint at the call, so it IS an array (or the
     * null of `?array`), whatever its erased static type says. The generator and
     * Traversable arms of an erased foreach — each a full copy of the body —
     * are dead for it. Every prelude `array_*` walker was exactly this shape.
     */
    private function isSettledArrayParam(Node $base): bool
    {
        if (!($base instanceof \Compile\Mir\LoadLocal)) { return false; }
        $name = $base->name;
        // The hint set is filled by the ordinary function emitter only; main and
        // a generator body are emitted elsewhere and would read a stale one.
        if ($this->writtenNamesFn !== $this->frame->name) { return false; }
        if (!isset($this->arrayHintedParams[$name])) { return false; }
        if (isset($this->locals->globalBacked[$name])) { return false; }
        return !isset($this->writtenNames[$name]);
    }

    /** @var array<string, bool> locals the current body may rebind ({@see collectWrittenNames}),
     *  collected at function entry — emission detaches statements as it goes */
    private array $writtenNames = [];
    /** The function {@see $writtenNames} and the hint set were collected for. */
    private string $writtenNamesFn = '';

    /**
     * Every local name the body may REBIND — conservative by design: a store, a
     * foreach variable, anything a reference or a by-ref closure capture
     * reaches, and any local handed directly to a call that is not a known free
     * function taking that argument by value.
     */
    private function collectWrittenNames(Node $n): void
    {
        if ($n instanceof \Compile\Mir\StoreLocal) {
            $this->writtenNames[$n->name] = true;
        } elseif ($n instanceof Foreach_) {
            $this->writtenNames[$n->valueVar] = true;
            if ($n->keyVar !== null) { $this->writtenNames[$n->keyVar] = true; }
        } elseif ($n instanceof \Compile\Mir\StaticLocalDecl_) {
            $this->writtenNames[$n->name] = true;
        } elseif ($n instanceof \Compile\Mir\RefAlias_ || $n instanceof \Compile\Mir\RefBind_
            || $n instanceof \Compile\Mir\RefAddr_ || $n instanceof \Compile\Mir\RefCell_) {
            $this->markLocalsWritten($n);
        } elseif ($n instanceof \Compile\Mir\Closure_) {
            $i = 0;
            foreach ($n->captures as $cap) {
                if (($n->captureByRef[$i] ?? false) && $cap instanceof \Compile\Mir\LoadLocal) {
                    $this->writtenNames[$cap->name] = true;
                }
                $i = $i + 1;
            }
        } elseif ($n->kind === Node::KIND_CALL || $n->kind === Node::KIND_METHOD_CALL
            || $n->kind === Node::KIND_STATIC_CALL || $n->kind === Node::KIND_NEW_OBJ
            || $n->kind === Node::KIND_INVOKE) {
            $refs = null;
            if ($n instanceof \Compile\Mir\Call && isset($this->sigs->paramTypes[$n->function])) {
                $refs = $this->sigs->refParams[$n->function] ?? [];
            }
            $args = $n instanceof \Compile\Mir\Call ? $n->args : \Compile\Mir\Walk::children($n);
            foreach ($args as $i => $c) {
                if ($c instanceof \Compile\Mir\LoadLocal && ($refs === null || ($refs[$i] ?? false))) {
                    $this->writtenNames[$c->name] = true;
                }
            }
        }
        foreach (\Compile\Mir\Walk::children($n) as $c) { $this->collectWrittenNames($c); }
    }

    private function markLocalsWritten(Node $n): void
    {
        if ($n instanceof \Compile\Mir\LoadLocal) { $this->writtenNames[$n->name] = true; }
        foreach (\Compile\Mir\Walk::children($n) as $c) { $this->markLocalsWritten($c); }
    }

    /** @var \Compile\Mir\ForeachSharedBody[] erased foreaches whose arms share one body, innermost last */
    private array $feShared = [];
    private int $feSharedSeq = 0;

    /**
     * A loop the emitter does not reset must hold no arena allocation of its
     * own: {@see ApplyMemoryMode} asked the same question on the same stamps and
     * demoted every allocation of a loop it refused. A disagreement would grow
     * the arena every iteration until the frame returns — never silently.
     */
    private function arenaBoundedOrFail(?Node $cond, Node $body, ?Node $step, Node $loop): void
    {
        if (!$this->arena->holdsArena($cond, $body, $step)) { return; }
        throw new \RuntimeException('EmitLlvm: ' . $this->frame->name . ': the loop at line '
            . (string)$loop->line . ' keeps arena allocations but takes no per-iteration reset'
            . ' (ApplyMemoryMode and the emitter disagree on ArenaContext::canResetPerIteration)');
    }

    private function inSharedForeach(\Compile\Mir\Foreach_ $fe): bool
    {
        $n = \count($this->feShared);
        return $n > 0 && $this->feShared[$n - 1]->fe === $fe;
    }

    /**
     * The loop body of one foreach arm, ending in a branch to `$stepLabel`. For
     * an erased base's arms ({@see \Compile\Mir\ForeachSharedBody}) the body
     * is emitted ONCE — each of the three arms used to carry its own copy — and
     * every arm enters it with its index; `$pushLoop` is false for an arm that
     * already entered its own loop frame.
     */
    private function emitForeachBodyArm(\Compile\Mir\Foreach_ $fe, string $endLabel, string $stepLabel, bool $pushLoop): string
    {
        $n = \count($this->feShared);
        $sh = $n > 0 ? $this->feShared[$n - 1] : null;
        if ($sh === null || $sh->fe !== $fe) {
            if ($pushLoop) { $this->cf->enterLoop($endLabel, $stepLabel); }
            $out = $this->emitNode($fe->body);
            if ($pushLoop) { $this->cf->leave(); }
            return $out . '  br label %' . $stepLabel . "\n";
        }
        $id = \count($sh->steps);
        $sh->steps[] = $stepLabel;
        $sh->ends[] = $endLabel;
        $out = '  store i64 ' . (string)$id . ', ptr ' . $sh->armSlot . "\n"
            . '  br label %' . $sh->bodyLabel . "\n";
        if (!$sh->bodyEmitted) {
            $sh->bodyEmitted = true;
            $out .= $sh->bodyLabel . ":\n";
            if ($sh->aggOwnSlot !== '') { $this->cf->pushAggIter($sh->aggIterSlot, true, $sh->aggOwnSlot); }
            $this->cf->enterLoop($sh->brkLabel, $sh->contLabel);
            $out .= $this->emitNode($fe->body);
            $this->cf->leave();
            if ($sh->aggOwnSlot !== '') { $this->cf->popAggIter(); }
            $out .= '  br label %' . $sh->contLabel . "\n";
        }
        return $out;
    }

    /** The shared body's way back to the arm that entered it; pops the entry. */
    private function emitForeachSharedDispatch(\Compile\Mir\Foreach_ $fe): string
    {
        $n = \count($this->feShared);
        if ($n === 0) { return ''; }
        $sh = $this->feShared[$n - 1];
        if ($sh->fe !== $fe) { return ''; }
        \array_pop($this->feShared);
        if (!$sh->bodyEmitted) { return ''; }
        return $this->armSwitchIr($sh->contLabel, $sh->armSlot, $sh->steps)
            . $this->armSwitchIr($sh->brkLabel, $sh->armSlot, $sh->ends);
    }

    /** @param string[] $targets */
    private function armSwitchIr(string $label, string $armSlot, array $targets): string
    {
        $a = $this->ssa->allocReg();
        $out = $label . ":\n" . '  ' . $a . ' = load i64, ptr ' . $armSlot . "\n";
        $out .= '  switch i64 ' . $a . ', label %' . $targets[0] . " [\n";
        $i = 0;
        foreach ($targets as $lbl) {
            if ($i > 0) { $out .= '    i64 ' . (string)$i . ', label %' . $lbl . "\n"; }
            $i = $i + 1;
        }
        return $out . "  ]\n";
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
        // A by-ref loop writes $v back: record whether the body left it a CELL.
        // Registered BEFORE any arm is emitted: over an erased base the body is
        // shared ({@see emitForeachBodyArm}) and is emitted inside whichever arm
        // comes first, so a flag allocated in the array arm was never the one the
        // body's stores recorded into. Only a YIELDING body cannot keep it (the
        // alloca does not survive a resume).
        $feFlag = '';
        $fePrev = $this->feCellFlags[$fe->valueVar] ?? null;
        $fePrevSet = isset($this->feCellFlagSet[$fe->valueVar]);
        if ($fe->byRef && !$this->foreachBodyYields($fe->body) && !isset($this->locals->refLocals[$fe->valueVar])) {
            $feFlag = $this->ssa->allocReg();
            $out .= '  ' . $feFlag . " = alloca i64\n";
            $this->feCellFlags[$fe->valueVar] = $feFlag;
            unset($this->feCellFlagSet[$fe->valueVar]);
        }
        // The live walk's slots exist before every arm: an erased base's body
        // is shared and may be emitted inside the generator arm, where its
        // write-backs must see "no array arm" (a null base).
        $live = $fe->byRef && !$this->foreachBodyYields($fe->body) && $this->unsetBaseIsWritable($fe->array);
        $liveSlot = '';
        $liveKey = '';
        if ($live) {
            $liveSlot = $this->ssa->allocReg();
            $out .= '  ' . $liveSlot . " = alloca ptr\n";
            $out .= '  store ptr null, ptr ' . $liveSlot . "\n";
            $liveKey = $this->ssa->allocReg();
            $out .= '  ' . $liveKey . " = alloca i64\n";
            $out .= '  store i64 0, ptr ' . $liveKey . "\n";
            $this->liveByRef[] = new \Compile\Mir\LiveByRefLoop($fe, $this->frame->name, $liveSlot, $liveKey, $feFlag);
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
            && !$this->foreachBodyYields($fe->body)
            && !$this->isSettledArrayParam($fe->array);
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
            $armSlot = $this->ssa->allocReg();
            $out .= '  ' . $armSlot . " = alloca i64\n";
            $this->feShared[] = new \Compile\Mir\ForeachSharedBody($fe, $armSlot,
                $this->ssa->allocLabel('fe.sh.body'), $this->ssa->allocLabel('fe.sh.cont'),
                $this->ssa->allocLabel('fe.sh.brk'));
            // The shared body may be emitted inside ANY arm (the first to reach
            // it), so the Traversable arm's owned iterator is registered for the
            // body itself: its slots exist, flag clear, before every arm.
            if ($this->hasTraversableClasses()) {
                $sh = $this->feShared[\count($this->feShared) - 1];
                $sh->aggIterSlot = $this->ssa->allocReg();
                $sh->aggOwnSlot = $this->ssa->allocReg();
                $out .= '  ' . $sh->aggIterSlot . " = alloca i64\n";
                $out .= '  ' . $sh->aggOwnSlot . " = alloca i1\n";
                $out .= '  store i1 0, ptr ' . $sh->aggOwnSlot . "\n";
            }
            // The shared body joins every arm and dispatches back to each arm's
            // step, so no arm's entry block dominates its own loop blocks any
            // more — exactly the generator-resume situation. Run the arms in
            // their FRAMED mode, which keeps the iterator state in slots and
            // reloads it per block, with two allocas standing in for the frame.
            if ($fe->genSlotBase < 0) {
                $fe->genSlotBase = 1000000 + $this->feSharedSeq;
                $this->feSharedSeq = $this->feSharedSeq + 1;
                $s0 = $this->ssa->allocReg();
                $s1 = $this->ssa->allocReg();
                $out .= '  ' . $s0 . " = alloca i64\n" . '  ' . $s1 . " = alloca i64\n";
                $this->locals->slots['@fe.0.' . (string)$fe->genSlotBase] = $s0;
                $this->locals->slots['@fe.1.' . (string)$fe->genSlotBase] = $s1;
            }
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
        // A fresh array iterable (a literal, a call result) is the loop's own:
        // given back at the end and on every jump out of the body.
        $iterFlavor = $this->ownedIterableFlavor($fe);
        $iterSlot = '';
        if ($iterFlavor !== '') {
            $iterSlot = $this->ssa->allocReg();
            $out .= '  ' . $iterSlot . " = alloca i64\n";
            $iw = $this->ssa->allocReg();
            $out .= '  ' . $iw . ' = ptrtoint ptr ' . $arr . " to i64\n";
            $out .= '  store i64 ' . $iw . ', ptr ' . $iterSlot . "\n";
        }
        // `foreach ($a as $k => $v) { … unset($a[$k]); … }` — PHP iterates a
        // SNAPSHOT of a by-value foreach, so the deletions do not disturb the
        // walk. That is not free here: an unset on a packed buffer promotes it
        // to the hashed layout, which RELOCATES, and the loop would keep
        // walking the freed base. Copy up front for this shape only; the copy
        // is a bounded leak (conservative direction — never free a buffer the
        // body may still hand out).
        if ($this->foreachBodyMutatesBase($fe)) {
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
        // A by-ref foreach over a base it can store through walks the LIVE
        // array, as php's does: the base is separated once up front, re-read
        // after every body, and the element found again BY KEY before $v is
        // written back. Walking the buffer captured at loop start wrote $v into
        // freed memory as soon as the body unset an element (an unset on a
        // packed buffer promotes and relocates) — symfony's
        // EventDispatcher::removeListener did exactly that on every run.
        // An erased base runs FRAMED too ({@see $dynGen}: slots stand in for a
        // frame because the shared body joins every arm) and that is no reason
        // to walk the captured buffer: only a body that YIELDS needs its state
        // in the generator frame, where the live slots below cannot live. The
        // framed walk wrote `$list` back into the buffer an `unset($list[$k])`
        // had just relocated — php-cs-fixer's EventDispatcher::removeListener.
        if ($live) {
            $out .= $this->emitSeparatedArray($fe->array, $bk === Type::KIND_CELL || $bk === Type::KIND_UNKNOWN);
            $sep = $this->lastValue;
            $snz = $this->ssa->allocReg();
            $out .= '  ' . $snz . ' = icmp eq ptr ' . $sep . ", null\n";
            $arr = $this->ssa->allocReg();
            $out .= '  ' . $arr . ' = select i1 ' . $snz . ', ptr @__mir_zero_word, ptr ' . $sep . "\n";
            $out .= '  store ptr ' . $arr . ', ptr ' . $liveSlot . "\n";
            $out .= '  store i64 0, ptr ' . $liveKey . "\n";
            $this->rt->needsCellKey = true;
        }

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
        if ($iterSlot !== '') { $this->cf->pushAggIter($iterSlot, false, '', $iterFlavor); }
        $this->cf->enterLoop($endLabel, $stepLabel);

        // Per-iteration arena reset. Safe because the save point is taken
        // *after* the iterable + iterator state (`$arr`, `$iSlot`, `$len`)
        // are materialized, so a reset never frees the array being walked.
        // By-ref foreach writes the value slot back into the element, so an
        // arena value could escape into the (pre-save) array — skip it.
        $reset = !$this->inSharedForeach($fe)
            && $this->arena->canResetForeach($fe, $this->frame->body, $this->gen->inGenerator, $this->frame->paramNames);
        if (!$reset) { $this->arenaBoundedOrFail(null, $fe->body, null, $fe); }
        if ($reset) { $out .= $this->emitArenaSave(); }
        $mark = $reset ? $this->arenaArmTryMark($fe) : -1;
        $out .= $this->arenaArmTryMarkIr($mark);
        $saved = [$this->arena->saveCurReg, $this->arena->saveUsedReg];

        $out .= '  br label %' . $condLabel . "\n";
        $out .= $condLabel . ":\n";
        if ($reset) { $out .= $this->emitArenaReset(); }
        if ($framed) {
            $out .= $this->genReloadArr($arrSlot);
            $arr = $this->lastValue;
            $len = $this->ssa->allocReg();
            $out .= '  ' . $len . ' = load i64, ptr ' . $arr . "\n";
        }
        if ($live) {
            $arr = $this->ssa->allocReg();
            $out .= '  ' . $arr . ' = load ptr, ptr ' . $liveSlot . "\n";
            $len = $this->ssa->allocReg();
            $out .= '  ' . $len . ' = call i64 @__mir_array_live_len(ptr ' . $arr . ")\n";
        }
        $i = $this->ssa->allocReg();
        $out .= '  ' . $i . ' = load i64, ptr ' . $iSlot . "\n";
        $c = $this->ssa->allocReg();
        $out .= '  ' . $c . ' = icmp slt i64 ' . $i . ', ' . $len . "\n";
        $out .= '  br i1 ' . $c . ', label %' . $bodyLabel . ', label %' . $endLabel . "\n";

        $out .= $bodyLabel . ":\n";
        if ($framed) { $out .= $this->genReloadArr($arrSlot); $arr = $this->lastValue; }
        if ($live) {
            $arr = $this->ssa->allocReg();
            $out .= '  ' . $arr . ' = load ptr, ptr ' . $liveSlot . "\n";
            $lk = $this->ssa->allocReg();
            $out .= '  ' . $lk . ' = call i64 @__mir_array_key_cell_at(ptr ' . $arr . ', i64 ' . $i . ")\n";
            // Held across the body: unsetting the element releases its stored
            // string key, and the lookup after the body still needs it.
            $out .= '  call void @__mir_cell_retain(i64 ' . $lk . ")\n";
            $out .= '  store i64 ' . $lk . ', ptr ' . $liveKey . "\n";
        }
        // element address + key
        $out .= $this->foreachElemAddrUnified($arr, $i);
        $valAddr = $this->feAddr;
        $valSlot = $this->locals->slots[$fe->valueVar];
        $ev = $this->ssa->allocReg();
        $out .= '  ' . $ev . ' = load i64, ptr ' . $valAddr . "\n";
        // A REF cell element is the BOX, not the value. foreach reads through
        // this inlined address rather than __mir_array_value_at (which derefs
        // for every other walker), so it needs the same arm: without it
        // `foreach ([&$d] as $v)` printed the box ADDRESS while `$e[0]` — the
        // keyed read, one file over — was right. Guarded like the keyed read
        // in {@see EmitLlvmArrays::emitArrayAccessUnified}, and carrying the
        // same cross-module caveat: see docs/design/reference-cells.md.
        $fel = $fe->array->type->element ?? null;
        // A CELL loop variable — a cell element, or any element of a cell BASE —
        // is decoded by the buffer's own hint, exactly as the keyed read is ({@see EmitLlvmArrays::emitArrayAccessUnified}) and
        // for the same reason; the store side re-encodes, so a value written
        // back into a raw-hinted buffer lands raw again. An ERASED element is a
        // cell too since InferTypes types it so ({@see InferNodes::inferForeach}).
        // The loop variable then starts as the DECODED cell, so the `&$v`
        // write-back must encode it again unless the body stored a raw value:
        // a raw-hinted buffer (a `['c', 'a']` literal
        // stored into an untyped property) got a tagged string where it keeps
        // raw pointers, and its release freed the tag bits as an address.
        $vDecodedCell = $fel === null || $fel->kind === Type::KIND_CELL || $fel->kind === Type::KIND_UNKNOWN;
        if (($fel !== null && ($fel->kind === Type::KIND_CELL || $fel->kind === Type::KIND_UNKNOWN))
            || $fel === null
            || $fe->array->type->kind === Type::KIND_CELL
            || $fe->array->type->kind === Type::KIND_UNKNOWN) {
            $ed = $this->ssa->allocReg();
            $out .= '  ' . $ed . ' = call i64 @__mir_elem_decode(ptr ' . $arr
                  . ', i64 ' . $ev . ")\n";
            $ev = $ed;
            $this->markCellOpaque($ev);
        }
        if ($this->rt->needsRefCells && $this->elemSlotMayHoldRef($fe->array->type)) {
            $this->rt->needsTagged = true;
            $dr = $this->ssa->allocReg();
            $out .= '  ' . $dr . ' = call i64 @__manticore_deref(i64 ' . $ev . ")\n";
            $this->propagateCellProvenance($ev, $dr);
            $ev = $dr;
        }
        // The opposite direction IS sound and is done: when the static element
        // type says STRING the value slot is read as a raw pointer everywhere, so
        // a slot that actually holds a boxed cell has to be stripped to its
        // payload. That claim is not always true — `foreach (array_values(
        // array_keys($assoc)) as $n) { str_contains($n, …) }` through a `string[]`
        // param walks cells — and it hands nothing downstream that the static
        // type did not already promise. {@see EmitLlvmArrays::emitArrayAccessUnified}
        // An ARRAY (or closure-like) element is a pointer too: `foreach
        // (json_decode($rows, true) as $p)` under a `vec[array{…}]` docblock
        // handed `$p` the NaN-boxed ARR word, and the shaped read then loaded
        // a header off the unmasked bits.
        if ($fel !== null
            && ($fel->kind === Type::KIND_STRING || $fel->kind === Type::KIND_OBJ
                || $fel->kind === Type::KIND_ARRAY || Type::isClosureLike($fel))) {
            $this->rt->needsElemUntag = true;
            $eu = $this->ssa->allocReg();
            $out .= '  ' . $eu . ' = call i64 @__mir_elem_untag(ptr ' . $arr
                  . ', i64 ' . $ev . ")\n";
            $ev = $eu;
        }
        // The scalar half ({@see EmitLlvmArrays::emitArrayAccessUnified}): a
        // concrete int/float/bool element over a buffer a cell-typed writer may
        // have cellified is unboxed by kind when the buffer says CELL.
        if ($fel !== null
            && ($fel->kind === Type::KIND_INT || $fel->kind === Type::KIND_FLOAT || $fel->kind === Type::KIND_BOOL)
            && $this->elemMayBeCellified($fe->array)) {
            $eu = $this->ssa->allocReg();
            $out .= '  ' . $eu . ' = call i64 @__mir_elem_untag_kind(ptr ' . $arr
                  . ', i64 ' . $ev . ', i64 ' . (string)$this->elementHintCodeForType($fel) . ")\n";
            $ev = $eu;
        }
        // ★ The loop variable CO-OWNS the element php would have copied into it.
        // Order is the property-slot order and for the same reason: take the +1
        // FIRST, then drop what the slot is losing, so a loop that meets the
        // same value twice goes 1 → 2 → 1 instead of freeing it. The retain and
        // this release are one emitter-local pair, so a disagreement with
        // {@see InsertMemoryOps::foreachValueCoOwns} can only STRAND the final
        // iteration's ref — never double-free it.
        if ($this->foreachValueOwns($fe)) {
            // The flavor of the value var's OWN scope-exit release, not one
            // re-derived from the element type: for an element that is itself
            // array-of-arrays the two differ — `discardReleaseFlavor` answers the
            // repr-mode `assoc`, the release a nested `…arrbuf` that drops the
            // inner buffers on every call — and a plain retain paired with that
            // release freed the inner arrays under the caller's literal
            // (`foreach ($others as $o)` over `[['a' => [3]]]`).
            $fvFlavor = $this->rcReleaseFlavor($this->frame->ownLocals[$fe->valueVar]);
            if ($fvFlavor !== '') {
                $out .= $this->rcRetainReg($ev, $fvFlavor);
                $out .= $this->foreachPrevDrop($fe, false);
            }
        } else {
            $out .= $this->foreachPrevDrop($fe, false);
        }
        $out .= $this->foreachVarStore($fe->valueVar, $ev, $fe->array->type->element);
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
            } elseif ($keyK === Type::KIND_STRING) {
                // A STRING-keyed array can still hold an INT entry — a `"0"`
                // literal key canonicalises to 0 at lowering, an int store
                // reaches an `array<string,_>` through erasure — and a packed
                // buffer has only indexes. The raw key_at handed that int back
                // as the "string pointer": key 0 read as NULL, and
                // `$_FILES[$k] = $v` over it SIGSEGVed. Box by entry kind and
                // render the scalar, exactly as a cell reaching a STRING consumer
                // does ({@see unboxCellToTypeRaw}); a real string key is stripped
                // back to its pointer, nothing more.
                //
                // ⚠ The rendered key is a MINTED heap string with no owner: the
                // key slot is a borrow slot ({@see InsertMemoryOps} blocks it,
                // it normally holds the hash entry's own key), so nothing
                // releases it — one small string per int entry met under a
                // string-typed key. This branch is the repair for a static key
                // type the array violates, not a hot path: every GPC-shaped
                // producer keys its arrays int|string (a tagged cell, the
                // `$keyIsCell` arm above, no mint). The arena is NOT an option —
                // an arena string is rc=-1, a container store keeps the raw
                // pointer and arena_leave reclaims it (the KEY case in
                // {@see InferAllocKind}) — and a per-loop release is not either:
                // a borrow copy of `$k` in the body outlives the next iteration.
                // The sound closure is co-owning the key slot the way
                // {@see InsertMemoryOps::foreachValueCoOwns} co-owns the value.
                $this->rt->needsCellToStrPtr = true;
                $this->rt->needsTaggedToStr = true;
                $kc = $this->ssa->allocReg();
                $out .= '  ' . $kc . ' = call i64 @__mir_array_key_cell_at(ptr ' . $arr . ', i64 ' . $i . ")\n";
                $ks = $this->ssa->allocReg();
                $out .= '  ' . $ks . ' = call ptr @__manticore_cell_to_strptr(i64 ' . $kc . ")\n";
                $out .= '  ' . $kp . ' = ptrtoint ptr ' . $ks . " to i64\n";
            } else {
                $out .= '  ' . $kp . ' = call i64 @__mir_array_key_at(ptr ' . $arr . ', i64 ' . $i . ")\n";
            }
            $keyIsCell = $kk === Type::KIND_CELL || $kk === Type::KIND_UNKNOWN
                || $vecErased || $keyK === Type::KIND_CELL;
            $out .= $this->foreachPrevDrop($fe, true);
            $out .= $this->foreachVarStore($fe->keyVar, $kp,
                $keyIsCell ? Type::cell() : $fe->array->type->key);
        }
        // The loop's own store left a cell exactly when it decoded one.
        if ($feFlag !== '') {
            $out .= '  store i64 ' . ($vDecodedCell ? '1' : '0') . ', ptr ' . $feFlag . "\n";
        }
        $out .= $this->emitForeachBodyArm($fe, $endLabel, $stepLabel, false);
        $feFlagUsed = $feFlag !== '' && ($vDecodedCell || isset($this->feCellFlagSet[$fe->valueVar]));

        $out .= $stepLabel . ":\n";
        if ($framed && $fe->byRef) { $out .= $this->genReloadArr($arrSlot); $arr = $this->lastValue; }
        $si = $this->ssa->allocReg();
        $out .= '  ' . $si . ' = load i64, ptr ' . $iSlot . "\n";
        if ($live) {
            $out .= $this->emitNode($fe->array);
            if ($bk === Type::KIND_CELL || $bk === Type::KIND_UNKNOWN) {
                $out .= $this->coerceToI64();
                $out .= $this->arrayPtrOrEmptyIr($this->lastValue);
                $na = $this->arrayPtrReg;
            } else {
                $out .= $this->coerceToPtr();
                $na = $this->lastValue;
            }
            $nnz = $this->ssa->allocReg();
            $out .= '  ' . $nnz . ' = icmp eq ptr ' . $na . ", null\n";
            $na2 = $this->ssa->allocReg();
            $out .= '  ' . $na2 . ' = select i1 ' . $nnz . ', ptr @__mir_zero_word, ptr ' . $na . "\n";
            $out .= '  store ptr ' . $na2 . ', ptr ' . $liveSlot . "\n";
            $lk = $this->ssa->allocReg();
            $out .= '  ' . $lk . ' = load i64, ptr ' . $liveKey . "\n";
            $pos = $this->ssa->allocReg();
            $out .= '  ' . $pos . ' = call i64 @__mir_array_pos_cell(ptr ' . $na2 . ', i64 ' . $lk . ")\n";
            $out .= '  call void @__mir_cell_drop(i64 ' . $lk . ")\n";
            $out .= '  store i64 0, ptr ' . $liveKey . "\n";
            $has = $this->ssa->allocReg();
            $out .= '  ' . $has . ' = icmp sge i64 ' . $pos . ", 0\n";
            $wbL = $this->ssa->allocLabel('fe.wb');
            // Gone: the tail slid down onto the current position, which is
            // therefore the next one to visit.
            $out .= '  br i1 ' . $has . ', label %' . $wbL . ', label %' . $condLabel . "\n";
            $out .= $wbL . ":\n";
            $out .= $this->foreachElemAddrUnified($na2, $pos);
            $wv = $this->ssa->allocReg();
            $out .= '  ' . $wv . ' = load i64, ptr ' . $this->locals->slots[$fe->valueVar] . "\n";
            $out .= $this->foreachWriteBackEncode($feFlagUsed ? $feFlag : '', $na2, $wv, $fe->array->type->element);
            $wb = $this->lastValue;
            $out .= $this->foreachRefTargetAddr($this->feAddr, $fe->array->type);
            $out .= '  store i64 ' . $wb . ', ptr ' . $this->feAddr . "\n";
            $np = $this->ssa->allocReg();
            $out .= '  ' . $np . ' = add i64 ' . $pos . ", 1\n";
            $out .= '  store i64 ' . $np . ', ptr ' . $iSlot . "\n";
            $out .= '  br label %' . $condLabel . "\n";
        } else {
            if ($fe->byRef) {
                $out .= $this->foreachElemAddrUnified($arr, $si);
                $wAddr = $this->feAddr;
                $wv = $this->ssa->allocReg();
                $out .= '  ' . $wv . ' = load i64, ptr ' . $this->locals->slots[$fe->valueVar] . "\n";
                $out .= $this->foreachWriteBackEncode($feFlagUsed ? $feFlag : '', $arr, $wv, $fe->array->type->element);
                $wb = $this->lastValue;
                $out .= $this->foreachRefTargetAddr($wAddr, $fe->array->type);
                $out .= '  store i64 ' . $wb . ', ptr ' . $this->feAddr . "\n";
            }
            $si2 = $this->ssa->allocReg();
            $out .= '  ' . $si2 . ' = add i64 ' . $si . ", 1\n";
            $out .= '  store i64 ' . $si2 . ', ptr ' . $iSlot . "\n";
            $out .= '  br label %' . $condLabel . "\n";
        }
        $out .= $endLabel . ":\n";
        // The last iteration (the final condition, a `break`) left its
        // allocations above the save: reclaim them on the way out too.
        if ($reset) { $out .= $this->arenaRestoreIr($saved[0], $saved[1]); }
        $out .= $this->arenaDisarmTryMark($mark);
        if ($live) {
            // A `break` leaves the body's key still held.
            $lk = $this->ssa->allocReg();
            $out .= '  ' . $lk . ' = load i64, ptr ' . $liveKey . "\n";
            $out .= '  call void @__mir_cell_drop(i64 ' . $lk . ")\n";
        }
        if ($iterSlot !== '') { $out .= $this->releaseAggIterSlot($iterSlot, false, '', $iterFlavor); }

        $this->cf->leave();
        if ($iterSlot !== '') { $this->cf->popAggIter(); }
        if ($feFlag !== '') {
            if ($fePrev === null) { unset($this->feCellFlags[$fe->valueVar]); }
            else { $this->feCellFlags[$fe->valueVar] = $fePrev; }
            if ($fePrevSet) { $this->feCellFlagSet[$fe->valueVar] = true; }
            else { unset($this->feCellFlagSet[$fe->valueVar]); }
        }
        // Rejoin the generator arm of the erased-base classify above.
        if ($dynEnd !== '') {
            $out .= '  br label %' . $dynEnd . "\n";
            $out .= $this->emitForeachSharedDispatch($fe);
            $out .= $dynEnd . ":\n";
            $this->lastValue = '0';
            $this->lastValueType = 'i64';
        }
        if ($live) {
            $out .= '  store ptr null, ptr ' . $liveSlot . "\n";
            \array_pop($this->liveByRef);
        }
        return $out;
    }

    /**
     * Write each enclosing live by-ref loop variable that statement `$s` names
     * back into its element, as the loop's step does.
     *
     * The variable is a copy sharing the element's count, written back by key
     * at the step. A write in between that RELOCATES it — an unset promoting a
     * packed buffer, an append growing it — frees the buffer the element still
     * names, and a body that then reaches the element another way read and
     * dropped freed memory: `unset($list[$k]); if (!$list) { unset($this->ls[$e][$p]); }`
     * (php-cs-fixer's EventDispatcher::removeListener). Keeping the element
     * current after every statement that names the variable closes that window.
     */
    private function liveByRefSyncIr(Node $s): string
    {
        if ($this->liveByRef === []) { return ''; }
        $k = $s->kind;
        if ($k === Node::KIND_RETURN || $k === Node::KIND_BREAK || $k === Node::KIND_CONTINUE
            || $k === Node::KIND_THROW || $k === Node::KIND_GOTO) { return ''; }
        $out = '';
        $lv = $this->lastValue;
        $lt = $this->lastValueType;
        foreach ($this->liveByRef as $lb) {
            if ($lb->fnName !== $this->frame->name) { continue; }
            if (!isset($this->locals->slots[$lb->fe->valueVar])) { continue; }
            if (!self::namesLocal($s, $lb->fe->valueVar)) { continue; }
            $out .= $this->liveByRefWriteBackIr($lb);
        }
        $this->lastValue = $lv;
        $this->lastValueType = $lt;
        return $out;
    }

    private static function namesLocal(Node $n, string $name): bool
    {
        if ($n instanceof LoadLocal && $n->name === $name) { return true; }
        if ($n instanceof StoreLocal && $n->name === $name) { return true; }
        if ($n->kind === Node::KIND_CLOSURE) { return false; }
        foreach (\Compile\Mir\Walk::children($n) as $c) {
            if (self::namesLocal($c, $name)) { return true; }
        }
        return false;
    }

    private function liveByRefWriteBackIr(\Compile\Mir\LiveByRefLoop $lb): string
    {
        $fe = $lb->fe;
        $bk = $fe->array->type->kind;
        $doL = $this->ssa->allocLabel('fe.sync');
        $wbL = $this->ssa->allocLabel('fe.syncwb');
        $endL = $this->ssa->allocLabel('fe.syncend');
        $cur = $this->ssa->allocReg();
        $out = '  ' . $cur . ' = load ptr, ptr ' . $lb->liveSlot . "\n";
        $off = $this->ssa->allocReg();
        $out .= '  ' . $off . ' = icmp eq ptr ' . $cur . ", null\n";
        $out .= '  br i1 ' . $off . ', label %' . $endL . ', label %' . $doL . "\n";
        $out .= $doL . ":\n";
        $out .= $this->emitNode($fe->array);
        if ($bk === Type::KIND_CELL || $bk === Type::KIND_UNKNOWN) {
            $out .= $this->coerceToI64();
            $out .= $this->arrayPtrOrEmptyIr($this->lastValue);
            $na = $this->arrayPtrReg;
        } else {
            $out .= $this->coerceToPtr();
            $na = $this->lastValue;
        }
        $nnz = $this->ssa->allocReg();
        $out .= '  ' . $nnz . ' = icmp eq ptr ' . $na . ", null\n";
        $na2 = $this->ssa->allocReg();
        $out .= '  ' . $na2 . ' = select i1 ' . $nnz . ', ptr @__mir_zero_word, ptr ' . $na . "\n";
        $out .= '  store ptr ' . $na2 . ', ptr ' . $lb->liveSlot . "\n";
        $lk = $this->ssa->allocReg();
        $out .= '  ' . $lk . ' = load i64, ptr ' . $lb->liveKey . "\n";
        $pos = $this->ssa->allocReg();
        $out .= '  ' . $pos . ' = call i64 @__mir_array_pos_cell(ptr ' . $na2 . ', i64 ' . $lk . ")\n";
        $has = $this->ssa->allocReg();
        $out .= '  ' . $has . ' = icmp sge i64 ' . $pos . ", 0\n";
        $out .= '  br i1 ' . $has . ', label %' . $wbL . ', label %' . $endL . "\n";
        $out .= $wbL . ":\n";
        $out .= $this->foreachElemAddrUnified($na2, $pos);
        $addr = $this->feAddr;
        $wv = $this->ssa->allocReg();
        $out .= '  ' . $wv . ' = load i64, ptr ' . $this->locals->slots[$fe->valueVar] . "\n";
        $out .= $this->foreachWriteBackEncode($lb->feFlag, $na2, $wv, $fe->array->type->element);
        $wb = $this->lastValue;
        $out .= $this->foreachRefTargetAddr($addr, $fe->array->type);
        $out .= '  store i64 ' . $wb . ', ptr ' . $this->feAddr . "\n";
        $out .= '  br label %' . $endL . "\n";
        $out .= $endL . ":\n";
        return $out;
    }

    /**
     * The word a by-ref foreach writes back into its element. The body may
     * have left $v a CELL (the name's merged type is cell when it is reused
     * across loops of different element kinds, and a compound assignment
     * stores the cell) while the loop itself stored the raw element: `$flag`
     * says which, at run time. A cell goes back in the element's own
     * representation — unboxed to a concrete static element type (a raw int
     * buffer read by int-typed code must not turn into cells), encoded by the
     * buffer's hint for a cell or erased one. Leaves the word in lastValue.
     */
    private function foreachWriteBackEncode(string $flag, string $arr, string $word, ?Type $elT): string
    {
        $this->lastValue = $word;
        $this->lastValueType = 'i64';
        if ($flag === '') { return ''; }
        $t = $this->ssa->allocReg();
        $out = '  ' . $t . " = alloca i64\n";
        $out .= '  store i64 ' . $word . ', ptr ' . $t . "\n";
        $f = $this->ssa->allocReg();
        $out .= '  ' . $f . ' = load i64, ptr ' . $flag . "\n";
        $c = $this->ssa->allocReg();
        $cellElT = $elT === null || $elT->kind === Type::KIND_CELL || $elT->kind === Type::KIND_UNKNOWN;
        // Flag: 0 = raw word for a raw buffer, 1 = a decoded cell, else the
        // element-hint code of a RAW value the body stored. A cell element
        // buffer takes every one of them as a cell, never a bare payload.
        $out .= '  ' . $c . ' = icmp ' . ($cellElT ? 'ne' : 'eq') . ' i64 ' . $f . ', ' . ($cellElT ? '0' : '1') . "\n";
        $encL = $this->ssa->allocLabel('fe.enc');
        $joinL = $this->ssa->allocLabel('fe.encj');
        $out .= '  br i1 ' . $c . ', label %' . $encL . ', label %' . $joinL . "\n";
        $out .= $encL . ":\n";
        if ($elT === null || $elT->kind === Type::KIND_CELL || $elT->kind === Type::KIND_UNKNOWN) {
            $bx = $this->ssa->allocReg();
            $out .= '  ' . $bx . ' = call i64 @__mir_box_by_repr(i64 ' . $word . ', i64 ' . $f . ")\n";
            $e = $this->ssa->allocReg();
            $out .= '  ' . $e . ' = call i64 @__mir_elem_encode(ptr ' . $arr . ', i64 ' . $bx . ")\n";
        } else {
            $this->lastValue = $word;
            $this->lastValueType = 'i64';
            $out .= $this->unboxCellToType($elT);
            $out .= $this->coerceToI64();
            $e = $this->lastValue;
        }
        $out .= '  store i64 ' . $e . ', ptr ' . $t . "\n";
        $out .= '  br label %' . $joinL . "\n";
        $out .= $joinL . ":\n";
        $w = $this->ssa->allocReg();
        $out .= '  ' . $w . ' = load i64, ptr ' . $t . "\n";
        $this->lastValue = $w;
        return $out;
    }

    /**
     * Unified-array value address for foreach entry `$i` → $this->feAddr.
     * Selects at runtime between the PACKED slot (HEADER + i*8) and the
     * HASHED entry value field (HEADER + i*ENTRY + VALUE) on the flags
     * word. One address serves both the read and the `&$v` writeback
     * (in-place value overwrite — no grow, so no relocation).
     */
    /**
     * Store a foreach loop variable — THROUGH its reference box when the
     * variable is ref-promoted, raw into its slot otherwise.
     *
     * `foreach ($xs as $v) { $pool[] = [$v, &$v]; }` SIGSEGVed: the `&$v`
     * makes {@see EmitLlvmModule::emitRefCellBoxes} turn `$v`'s slot into a
     * BOX ADDRESS, every read of `$v` then goes through that address — and the
     * loop's own store wrote the element straight into the slot, so the next
     * read dereferenced the element as a pointer (0xfff1000000000001, a tagged
     * int used as an address). This is emitStoreLocal's ref arm, which the
     * foreach store never went through. The box is a CELL channel, so a raw
     * element is NaN-boxed by its static type first, exactly as a `$v = …`
     * assignment to a ref-taken local is.
     */
    private function foreachVarStore(string $name, string $reg, ?Type $valType): string
    {
        $slot = $this->locals->slots[$name];
        if (!isset($this->locals->refLocals[$name])) {
            return '  store i64 ' . $reg . ', ptr ' . $slot . "\n";
        }
        $out = '';
        $val = $reg;
        $wantCell = ($this->locals->refParamTypes[$name] ?? null) !== null
            && $this->locals->refParamTypes[$name]->kind === Type::KIND_CELL;
        if ($wantCell && $valType !== null && $valType->kind !== Type::KIND_CELL
            && $valType->kind !== Type::KIND_UNKNOWN && $this->isCellBoxableArg($valType)) {
            $this->lastValue = $reg;
            $this->lastValueType = 'i64';
            $out .= $this->boxToCell($valType);
            $val = $this->lastValue;
        }
        $addr = $this->ssa->allocReg();
        $out .= '  ' . $addr . ' = load i64, ptr ' . $slot . "\n";
        $p = $this->ssa->allocReg();
        $out .= '  ' . $p . ' = inttoptr i64 ' . $addr . " to ptr\n";
        $out .= '  store i64 ' . $val . ', ptr ' . $p . "\n";
        return $out;
    }

    /**
     * Where a by-ref foreach writes `$v` back: the element slot, or — when the
     * slot holds a REFERENCE cell (a `&...$xs` pack, `[&$a, &$b]`) — the box it
     * points at, so the write reaches the bound variable and the binding stays.
     * Writing the slot replaced the reference with the value and lost the
     * write. Only a slot that holds cells can hold a reference; any other
     * buffer keeps the plain slot. Leaves the address in {@see $feAddr}.
     */
    private function foreachRefTargetAddr(string $slot, Type $arrT): string
    {
        $this->feAddr = $slot;
        // The predicate the element store's write-through uses
        // ({@see EmitLlvmArrays::elemSlotMayHoldRef}): a cell or erased element
        // (an erased one is what a bare `array &` holds, refs included); a
        // concrete element buffer never holds a reference box.
        if (!$this->elemSlotMayHoldRef($arrT)) { return ''; }
        $cur = $this->ssa->allocReg();
        $istag = $this->ssa->allocReg();
        $sh = $this->ssa->allocReg();
        $nib = $this->ssa->allocReg();
        $isr = $this->ssa->allocReg();
        $both = $this->ssa->allocReg();
        $mask = $this->ssa->allocReg();
        $boxp = $this->ssa->allocReg();
        $dst = $this->ssa->allocReg();
        $out  = '  ' . $cur . ' = load i64, ptr ' . $slot . "\n";
        $out .= '  ' . $istag . ' = icmp ugt i64 ' . $cur . ", -4503599627370496\n";
        $out .= '  ' . $sh . ' = lshr i64 ' . $cur . ", 48\n";
        $out .= '  ' . $nib . ' = and i64 ' . $sh . ", 15\n";
        $out .= '  ' . $isr . ' = icmp eq i64 ' . $nib . ', ' . (string)\Compile\MemoryAbi::CELL_TAG_REF . "\n";
        $out .= '  ' . $both . ' = and i1 ' . $istag . ', ' . $isr . "\n";
        $out .= '  ' . $mask . ' = and i64 ' . $cur . ', ' . (string)\Compile\MemoryAbi::CELL_PAYLOAD_MASK . "\n";
        $out .= '  ' . $boxp . ' = inttoptr i64 ' . $mask . " to ptr\n";
        $out .= '  ' . $dst . ' = select i1 ' . $both . ', ptr ' . $boxp . ', ptr ' . $slot . "\n";
        $this->feAddr = $dst;
        return $out;
    }

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
                    $sPair = $this->structPair($sw->subject->type, $arm->value->type);
                    if ($sPair !== '') {
                        $out .= $this->structCmpIr($sPair, $subj, 'i64', $v, 'i64', true);
                        $out .= '  ' . $eq . ' = icmp ne i64 ' . $this->lastValue . ", 0\n";
                    } elseif ($this->looseObjPair($sw->subject->type, $arm->value->type)) {
                        // An object subject or arm: php's `==` on objects is
                        // structural, and a raw pointer never matched a boxed one.
                        $out .= $this->looseObjCmpIr($subj, 'i64', $sw->subject->type, $v, 'i64', $arm->value->type, true);
                        $out .= '  ' . $eq . ' = icmp ne i64 ' . $this->lastValue . ", 0\n";
                    } elseif ($useStr) {
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
        $armVals = [];
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
                        } elseif ($vk === Type::KIND_OBJ
                            && isset($this->enums[$c->type->class ?? ''])) {
                            // ENUM cond against a cell subject (`match ($m)`
                            // where `$m` is a `?Enum`, hence a cell). The cond
                            // is a raw ORDINAL and the subject is
                            // box_object(singleton), so unbox_int below would
                            // compare a tagged pointer with a small int and
                            // every arm fell to `default`. Box the cond to its
                            // own singleton cell and compare carriers — the
                            // same identity rule `===` uses (EmitLlvmExpr).
                            $out .= $this->emitNode($c);
                            $out .= $this->boxToCell($c->type);
                            $out .= $this->coerceToI64();
                            $out .= '  ' . $eq . ' = icmp eq i64 ' . $subj . ', ' . $this->lastValue . "\n";
                        } elseif ($this->isObjishType($c->type)) {
                            // An object cond against a cell subject: the same
                            // instance ({@see objCellSameIr}), never an unboxed int.
                            $out .= $this->emitNode($c);
                            $out .= $this->coerceToI64();
                            $out .= $this->objCellSameIr($this->lastValue, $subj);
                            $out .= '  ' . $eq . ' = or i1 ' . $this->lastValue . ", false\n";
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
                        } elseif ($vk === Type::KIND_CELL && $this->isObjishType($m->subject->type)) {
                            // An object subject against a cell cond: identity by payload.
                            $out .= $this->objCellSameIr($subj, $cv);
                            $out .= '  ' . $eq . ' = or i1 ' . $this->lastValue . ", false\n";
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
                $out .= $this->boxToCell($arm->body->type, $arm->body);
            } else {
                $out .= $this->armCoerce($n, $arm->body);
            }
            $out .= $this->armRetainPostBox($n, $arm->body, $this->lastValue);
            $out .= '  store i64 ' . $this->lastValue . ', ptr ' . $res . "\n";
            $armVals[] = $this->lastValue;
            $out .= '  br label %' . $endLabel . "\n";
            $out .= $afterLabel . ":\n";
        }
        // No arm matched (no default) — yield 0 (PHP throws; we don't).
        $out .= '  br label %' . $endLabel . "\n";
        $out .= $endLabel . ":\n";
        $loaded = $this->ssa->allocReg();
        $out .= '  ' . $loaded . ' = load i64, ptr ' . $res . "\n";
        if ($wantCell) { $this->joinCellProvenance($armVals, $loaded); }
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

        $reset = $this->arena->canResetPerIteration($w->cond, $w->body, null, $this->frame->body, $this->gen->inGenerator, $this->frame->paramNames);
        if (!$reset) { $this->arenaBoundedOrFail($w->cond, $w->body, null, $w); }
        $out = '';
        if ($reset) { $out .= $this->emitArenaSave(); }
        $mark = $reset ? $this->arenaArmTryMark($n) : -1;
        $out .= $this->arenaArmTryMarkIr($mark);
        $saved = [$this->arena->saveCurReg, $this->arena->saveUsedReg];
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
        // The last iteration (the final condition, a `break`) left its
        // allocations above the save: reclaim them on the way out too.
        if ($reset) { $out .= $this->arenaRestoreIr($saved[0], $saved[1]); }
        $out .= $this->arenaDisarmTryMark($mark);

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

        $reset = $this->arena->canResetPerIteration($f->cond, $f->body, $f->step, $this->frame->body, $this->gen->inGenerator, $this->frame->paramNames);
        if (!$reset) { $this->arenaBoundedOrFail($f->cond, $f->body, $f->step, $f); }
        $out = '';
        if ($f->init !== null) { $out .= $this->emitNode($f->init); }
        if ($reset) { $out .= $this->emitArenaSave(); }
        $mark = $reset ? $this->arenaArmTryMark($n) : -1;
        $out .= $this->arenaArmTryMarkIr($mark);
        $saved = [$this->arena->saveCurReg, $this->arena->saveUsedReg];
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
        // The last iteration (the final condition, a `break`) left its
        // allocations above the save: reclaim them on the way out too.
        if ($reset) { $out .= $this->arenaRestoreIr($saved[0], $saved[1]); }
        $out .= $this->arenaDisarmTryMark($mark);

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

        $reset = $this->arena->canResetPerIteration($d->cond, $d->body, null, $this->frame->body, $this->gen->inGenerator, $this->frame->paramNames);
        if (!$reset) { $this->arenaBoundedOrFail($d->cond, $d->body, null, $d); }
        $out = '';
        if ($reset) { $out .= $this->emitArenaSave(); }
        $mark = $reset ? $this->arenaArmTryMark($n) : -1;
        $out .= $this->arenaArmTryMarkIr($mark);
        $saved = [$this->arena->saveCurReg, $this->arena->saveUsedReg];
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
        // The last iteration (the final condition, a `break`) left its
        // allocations above the save: reclaim them on the way out too.
        if ($reset) { $out .= $this->arenaRestoreIr($saved[0], $saved[1]); }
        $out .= $this->arenaDisarmTryMark($mark);

        $this->cf->leave();
        return $out;
    }
}
