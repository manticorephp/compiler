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
use Compile\Mir\NewDynObj;
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
 * Dispatch surface: one method per MIR node type.
 *
 * A node routes here through `accept()` — see {@see \Compile\Mir\EmitVisitor}.
 * Each method receives the CONCRETE node, so its fields are read directly.
 *
 * A trait on the one {@see EmitLlvm} host — the split is by concern, so a reader
 * opens the file for the thing they are looking at instead of scrolling one
 * 8k-line class. State stays on the host and its collaborators.
 */
trait EmitLlvmVisit
{
    public function visitIntConst(IntConst $n): string
    {
        $this->lastValue = (string)$n->value;
        $this->lastValueType = 'i64';
        return '';
    }

    public function visitFloatConst(FloatConst $n): string
    {
        $this->lastValue = $this->formatFloat($n->value);
        $this->lastValueType = 'double';
        return '';
    }

    public function visitStringConst(StringConst $n): string
    {
        return $this->emitStringConst($n);
    }

    public function visitBoolConst(BoolConst $n): string
    {
        $this->lastValue = $n->value ? '1' : '0';
        $this->lastValueType = 'i64';
        return '';
    }

    public function visitNullConst(NullConst $n): string
    {
        $this->lastValue = '0';
        $this->lastValueType = 'i64';
        return '';
    }

    public function visitLoadLocal(LoadLocal $n): string
    {
        return $this->emitLoadLocal($n);
    }

    public function visitStoreLocal(StoreLocal $n): string
    {
        return $this->emitStoreLocal($n);
    }

    public function visitAdd(Add $n): string
    {
        return $this->emitArith($n, $this->binLeft($n), $this->binRight($n), 'add', 'fadd');
    }

    public function visitSub(Sub $n): string
    {
        return $this->emitArith($n, $this->binLeft($n), $this->binRight($n), 'sub', 'fsub');
    }

    public function visitMul(Mul $n): string
    {
        return $this->emitArith($n, $this->binLeft($n), $this->binRight($n), 'mul', 'fmul');
    }

    public function visitDiv(Div $n): string
    {
        return $this->emitDiv($n);
    }

    public function visitMod(Mod $n): string
    {
        return $this->emitArith($n, $this->binLeft($n), $this->binRight($n), 'srem', 'frem');
    }

    public function visitNeg(Neg $n): string
    {
        return $this->emitNeg($n);
    }

    public function visitNot(Not_ $n): string
    {
        return $this->emitNot($n);
    }

    public function visitBitOp(BitOp $n): string
    {
        return $this->emitBitOp($n);
    }

    public function visitBitNot(BitNot_ $n): string
    {
        return $this->emitBitNot($n);
    }

    public function visitConcat(Concat $n): string
    {
        return $this->emitConcat($n);
    }

    public function visitEcho(Echo_ $n): string
    {
        return $this->emitEcho($n);
    }

    public function visitReturn(Return_ $n): string
    {
        return $this->emitReturn($n);
    }

    public function visitCall(Call $n): string
    {
        return $this->emitCall($n);
    }

    public function visitBlock(Block $n): string
    {
            $out = '';
            foreach ($n->stmts as $s) {
                $out .= $this->emitNode($s);
                $out .= $this->emitDiscardedCallRelease($s);
            }
            return $out;
    }

    public function visitMemoryOp(MemoryOp_ $n): string
    {
        return $this->emitMemoryOp($n);
    }

    public function visitSpaceship(\Compile\Mir\Spaceship $n): string
    {
        return $this->emitSpaceship($n);
    }

    public function visitCmp(Cmp $n): string
    {
        return $this->emitCmp($n);
    }

    public function visitIf(If_ $n): string
    {
        return $this->emitIf($n);
    }

    public function visitWhile(While_ $n): string
    {
        return $this->emitWhile($n);
    }

    public function visitIncDec(IncDec $n): string
    {
        return $this->emitIncDec($n);
    }

    public function visitStaticProp(StaticProp_ $n): string
    {
        return $this->emitStaticProp($n);
    }

    public function visitStoreStaticProp(StoreStaticProp_ $n): string
    {
        return $this->emitStoreStaticProp($n);
    }

    public function visitStaticLocalDecl(StaticLocalDecl_ $n): string
    {
        return $this->emitStaticLocalDecl($n);
    }

    public function visitThrow(Throw_ $n): string
    {
        return $this->emitThrow($n);
    }

    public function visitYield(Yield_ $n): string
    {
        return $this->emitYield($n);
    }

    public function visitTryCatch(TryCatch_ $n): string
    {
        return $this->emitTryCatch($n);
    }

    public function visitRefAlias(RefAlias_ $n): string
    {
        return $this->emitRefAlias($n);
    }

    public function visitRefBind(RefBind_ $n): string
    {
        return $this->emitRefBind($n);
    }

    public function visitRefAddr(RefAddr_ $n): string
    {
        return $this->emitRefAddr($n);
    }

    public function visitGoto(Goto_ $n): string
    {
        return '  br label %' . $this->ssa->userLabel($n->label) . "\n" . $this->emitDeadLabel();
    }

    public function visitLabel(Label_ $n): string
    {
        $l = $this->ssa->userLabel($n->name);
        return '  br label %' . $l . "\n" . $l . ":\n";
    }

    public function visitClassName(ClassName_ $n): string
    {
        return $this->emitClassName($n);
    }

    public function visitIsset(Isset_ $n): string
    {
        return $this->emitIsset($n);
    }

    public function visitUnset(Unset_ $n): string
    {
        return $this->emitUnset($n);
    }

    public function visitClosure(Closure_ $n): string
    {
        return $this->emitClosure($n);
    }

    public function visitInvoke(Invoke_ $n): string
    {
        return $this->emitInvoke($n);
    }

    public function visitNullCoalesce(NullCoalesce_ $n): string
    {
        return $this->emitNullCoalesce($n);
    }

    public function visitInstanceof(Instanceof_ $n): string
    {
        return $this->emitInstanceof($n);
    }

    public function visitCast(Cast $n): string
    {
        return $this->emitCast($n);
    }

    public function visitTernary(Ternary $n): string
    {
        return $this->emitTernary($n);
    }

    public function visitSwitch(Switch_ $n): string
    {
        return $this->emitSwitch($n);
    }

    public function visitMatch(Match_ $n): string
    {
        return $this->emitMatch($n);
    }

    public function visitForeach(Foreach_ $n): string
    {
        return $this->emitForeach($n);
    }

    public function visitFor(For_ $n): string
    {
        return $this->emitFor($n);
    }

    public function visitDoWhile(DoWhile_ $n): string
    {
        return $this->emitDoWhile($n);
    }

    // A `break`/`continue` out of a try skips the fall-through pop exactly as a
    // `return` does — and from a loop it leaks a jmp slot per ITERATION. Hand
    // back every slot opened inside the target loop before branching.
    public function visitBreak(Break_ $n): string
    {
        return $this->restoreJmpDepth($this->cf->loopDepthReg($n->level), $this->cf->loopDepthSlot($n->level))
             . '  br label %' . $this->cf->breakTarget($n->level) . "\n" . $this->emitDeadLabel();
    }

    public function visitContinue(Continue_ $n): string
    {
        return $this->restoreJmpDepth($this->cf->loopDepthReg($n->level), $this->cf->loopDepthSlot($n->level))
             . '  br label %' . $this->cf->continueTarget($n->level) . "\n" . $this->emitDeadLabel();
    }

    public function visitArrayLit(ArrayLit $n): string
    {
        return $this->emitArrayLit($n);
    }

    public function visitArrayAccess(ArrayAccess_ $n): string
    {
        return $this->emitArrayAccess($n);
    }

    public function visitSpread(Spread_ $n): string
    {
        // No emit arm: a Spread_ is consumed by its parent node, never emitted
        // on its own (the old chain fell through to an empty string here).
        return '';
    }

    public function visitStoreElement(StoreElement $n): string
    {
        return $this->emitStoreElement($n);
    }

    public function visitNewDynObj(NewDynObj $n): string
    {
        return $this->emitNewDynObj($n);
    }

    public function visitNewObj(NewObj $n): string
    {
        return $this->emitNewObj($n);
    }

    public function visitPropertyAccess(PropertyAccess_ $n): string
    {
        return $this->emitPropertyAccess($n);
    }

    public function visitClone(Clone_ $n): string
    {
        return $this->emitClone($n);
    }

    public function visitDynProp(DynProp_ $n): string
    {
        return $this->emitDynProp($n);
    }

    public function visitStoreDynProp(StoreDynProp_ $n): string
    {
        return $this->emitStoreDynProp($n);
    }

    public function visitStoreProperty(StoreProperty $n): string
    {
        return $this->emitStoreProperty($n);
    }

    public function visitMethodCall(MethodCall_ $n): string
    {
        return $this->emitMethodCall($n);
    }

    public function visitStaticCall(StaticCall_ $n): string
    {
        return $this->emitStaticCall($n);
    }
}
