<?php

namespace Compile\Mir;

/**
 * Emits one MIR node. One method per node type — the compiler dispatches on its
 * own IR by virtual call rather than by walking a chain of `kind ===` tests.
 *
 * Each visit method receives the CONCRETE node, so its fields are reached
 * directly instead of through the `Node` base. Adding a node type breaks every
 * implementation at class-load until it is handled, which is the point: the old
 * chain silently fell through to an empty string.
 */
interface EmitVisitor
{
    public function visitIntConst(IntConst $n): string;
    public function visitFloatConst(FloatConst $n): string;
    public function visitStringConst(StringConst $n): string;
    public function visitBoolConst(BoolConst $n): string;
    public function visitNullConst(NullConst $n): string;
    public function visitLoadLocal(LoadLocal $n): string;
    public function visitStoreLocal(StoreLocal $n): string;
    public function visitAdd(Add $n): string;
    public function visitSub(Sub $n): string;
    public function visitMul(Mul $n): string;
    public function visitDiv(Div $n): string;
    public function visitMod(Mod $n): string;
    public function visitNeg(Neg $n): string;
    public function visitNot(Not_ $n): string;
    public function visitBitOp(BitOp $n): string;
    public function visitBitNot(BitNot_ $n): string;
    public function visitConcat(Concat $n): string;
    public function visitEcho(Echo_ $n): string;
    public function visitReturn(Return_ $n): string;
    public function visitCall(Call $n): string;
    public function visitBlock(Block $n): string;
    public function visitMemoryOp(MemoryOp_ $n): string;
    public function visitCmp(Cmp $n): string;
    public function visitSpaceship(\Compile\Mir\Spaceship $n): string;
    public function visitIf(If_ $n): string;
    public function visitWhile(While_ $n): string;
    public function visitIncDec(IncDec $n): string;
    public function visitStaticProp(StaticProp_ $n): string;
    public function visitStoreStaticProp(StoreStaticProp_ $n): string;
    public function visitStaticLocalDecl(StaticLocalDecl_ $n): string;
    public function visitThrow(Throw_ $n): string;
    public function visitYield(Yield_ $n): string;
    public function visitTryCatch(TryCatch_ $n): string;
    public function visitRefAlias(RefAlias_ $n): string;
    public function visitRefBind(RefBind_ $n): string;
    public function visitRefAddr(RefAddr_ $n): string;
    public function visitGoto(Goto_ $n): string;
    public function visitLabel(Label_ $n): string;
    public function visitClassName(ClassName_ $n): string;
    public function visitIsset(Isset_ $n): string;
    public function visitUnset(Unset_ $n): string;
    public function visitClosure(Closure_ $n): string;
    public function visitInvoke(Invoke_ $n): string;
    public function visitNullCoalesce(NullCoalesce_ $n): string;
    public function visitInstanceof(Instanceof_ $n): string;
    public function visitCast(Cast $n): string;
    public function visitTernary(Ternary $n): string;
    public function visitSwitch(Switch_ $n): string;
    public function visitMatch(Match_ $n): string;
    public function visitForeach(Foreach_ $n): string;
    public function visitFor(For_ $n): string;
    public function visitDoWhile(DoWhile_ $n): string;
    public function visitBreak(Break_ $n): string;
    public function visitContinue(Continue_ $n): string;
    public function visitArrayLit(ArrayLit $n): string;
    public function visitArrayAccess(ArrayAccess_ $n): string;
    public function visitSpread(Spread_ $n): string;
    public function visitStoreElement(StoreElement $n): string;
    public function visitNewObj(NewObj $n): string;
    public function visitNewDynObj(NewDynObj $n): string;
    public function visitPropertyAccess(PropertyAccess_ $n): string;
    public function visitClone(Clone_ $n): string;
    public function visitDynProp(DynProp_ $n): string;
    public function visitStoreDynProp(StoreDynProp_ $n): string;
    public function visitStoreProperty(StoreProperty $n): string;
    public function visitMethodCall(MethodCall_ $n): string;
    public function visitStaticCall(StaticCall_ $n): string;
}
