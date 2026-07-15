<?php

namespace Compile\Mir;

/**
 * Structural deep-copy of MIR nodes — produces a fresh tree with no
 * node-object sharing, so a transform (e.g. {@see Passes\Monomorphize})
 * can hold multiple independently-typed copies of one function body.
 *
 * Mirrors {@see Walk::children} in coverage: every node kind that has
 * sub-nodes reconstructs them recursively. Leaf payloads (literal
 * values, op strings, names) copy by value. `Type` instances are shared
 * by reference — they are reassigned (not mutated) by InferTypes, which
 * re-runs over each clone and overwrites every `->type`.
 *
 * An unrecognised kind throws; callers that may encounter not-yet-
 * supported shapes (closures, generators) should gate against them
 * before cloning, or catch and skip.
 */
final class NodeClone
{
    public static function block(Block $b): Block
    {
        return self::node($b);
    }

    public static function node(Node $n): Node
    {
        $k = $n->kind;

        // ── Leaves (copy payload, no children) ────────────────────
        if ($k === Node::KIND_INT_CONST)    { $x = self::asInt($n);    return new IntConst($x->value, $n->type); }
        if ($k === Node::KIND_FLOAT_CONST)  { $x = self::asFloat($n);  return new FloatConst($x->value, $n->type); }
        if ($k === Node::KIND_STRING_CONST) { $x = self::asStr($n);    return new StringConst($x->value, $n->type); }
        if ($k === Node::KIND_BOOL_CONST)   { $x = self::asBool($n);   return new BoolConst($x->value, $n->type); }
        if ($k === Node::KIND_NULL_CONST)   { return new NullConst($n->type); }
        if ($k === Node::KIND_LOAD_LOCAL)   { $x = self::asLoadLocal($n); return new LoadLocal($x->name, $n->type); }
        if ($k === Node::KIND_STATIC_PROP)  { $x = self::asStaticProp($n); return new StaticProp_($x->global, $n->type); }
        if ($k === Node::KIND_BREAK)        { $x = self::asBreak($n);    return new Break_($x->level); }
        if ($k === Node::KIND_CONTINUE)     { $x = self::asContinue($n); return new Continue_($x->level); }
        if ($k === Node::KIND_GOTO)         { return new \Compile\Mir\Goto_(self::asGoto($n)->label, $n->type); }
        if ($k === Node::KIND_LABEL)        { return new \Compile\Mir\Label_(self::asLabel($n)->name, $n->type); }
        if ($k === Node::KIND_INCDEC)       { $x = self::asIncDec($n);   return new IncDec($x->name, $x->op, $x->prefix, $n->type); }
        if ($k === Node::KIND_REF_ALIAS)    { $x = self::asRefAlias($n); return new RefAlias_($x->target, $x->source, $n->type); }

        // ── Arithmetic / compare (left,right) ─────────────────────
        if ($k === Node::KIND_ADD) { $x = self::asAdd($n); return new Add(self::node($x->left), self::node($x->right), $n->type); }
        if ($k === Node::KIND_SUB) { $x = self::asSub($n); return new Sub(self::node($x->left), self::node($x->right), $n->type); }
        if ($k === Node::KIND_MUL) { $x = self::asMul($n); return new Mul(self::node($x->left), self::node($x->right), $n->type); }
        if ($k === Node::KIND_DIV) { $x = self::asDiv($n); return new Div(self::node($x->left), self::node($x->right), $n->type); }
        if ($k === Node::KIND_MOD) { $x = self::asMod($n); return new Mod(self::node($x->left), self::node($x->right), $n->type); }
        if ($k === Node::KIND_CMP) { $x = self::asCmp($n); return new Cmp(self::node($x->left), self::node($x->right), $x->op); }
        if ($k === Node::KIND_CONCAT) { $x = self::asConcat($n); return new Concat(self::node($x->left), self::node($x->right)); }
        if ($k === Node::KIND_BITOP) { $x = self::asBitOp($n); return new BitOp($x->op, self::node($x->left), self::node($x->right), $n->type); }

        // ── Unary ─────────────────────────────────────────────────
        if ($k === Node::KIND_NEG)    { $x = self::asNeg($n);    return new Neg(self::node($x->operand), $n->type); }
        if ($k === Node::KIND_NOT)    { $x = self::asNot($n);    return new Not_(self::node($x->operand)); }
        if ($k === Node::KIND_BITNOT) { $x = self::asBitNot($n); return new BitNot_(self::node($x->operand), $n->type); }
        if ($k === Node::KIND_CAST)   { $x = self::asCast($n);   return new Cast($x->target, self::node($x->operand), $n->type); }
        if ($k === Node::KIND_CLASS_NAME) { $x = self::asClassName($n); return new ClassName_(self::node($x->operand), $n->type); }
        if ($k === Node::KIND_INSTANCEOF) { $x = self::asInstanceof($n); return new Instanceof_(self::node($x->operand), $x->class); }

        // ── Statements / expressions with children ────────────────
        if ($k === Node::KIND_ECHO)   { $x = self::asEcho($n);   return new Echo_(self::nodes($x->exprs), $n->type); }
        if ($k === Node::KIND_RETURN) { $x = self::asReturn($n); return new Return_($x->value === null ? null : self::node($x->value), $n->type); }
        if ($k === Node::KIND_CALL)   { $x = self::asCall($n);   return new Call($x->function, self::nodes($x->args), $n->type); }
        if ($k === Node::KIND_INVOKE) { $x = self::asInvoke($n); return new Invoke_(self::node($x->callee), self::nodes($x->args), $n->type); }
        // Shallow: clones the Closure_ NODE, reusing the underlying `__closure_N`
        // FunctionDef (same `id` / `->class`). Correct when the enclosing clone
        // does NOT need its own closure fn (Phase A callable-dim specialization,
        // where the closure's captures are already concretely typed at the
        // caller). Freshening the closure fn per specialization is Phase B.
        if ($k === Node::KIND_CLOSURE) { $x = self::asClosure($n); return new Closure_($x->id, self::nodes($x->captures), $n->type, $x->captureByRef); }
        if ($k === Node::KIND_STORE_LOCAL) { $x = self::asStoreLocal($n); return new StoreLocal($x->name, self::node($x->value), $n->type); }
        if ($k === Node::KIND_NULLCOALESCE) { $x = self::asNullCoalesce($n); return new NullCoalesce_(self::node($x->left), self::node($x->right), $n->type); }
        if ($k === Node::KIND_SPREAD) { $x = self::asSpread($n); return new Spread_(self::node($x->operand), $n->type); }
        if ($k === Node::KIND_THROW)  { $x = self::asThrow($n);  return new Throw_(self::node($x->value), $n->type); }
        if ($k === Node::KIND_REF_BIND) { $x = self::asRefBind($n); return new RefBind_($x->target, self::node($x->call), $n->type); }
        if ($k === Node::KIND_REF_ADDR) { $x = self::asRefAddr($n); return new RefAddr_($x->target, self::node($x->lvalue), $n->type); }
        if ($k === Node::KIND_STORE_STATIC_PROP) { $x = self::asStoreStaticProp($n); return new StoreStaticProp_($x->global, self::node($x->value), $n->type); }
        if ($k === Node::KIND_ISSET)  { $x = self::asIsset($n);  return new Isset_(self::nodes($x->targets), $n->type); }
        if ($k === Node::KIND_UNSET)  { $x = self::asUnset($n);  return new Unset_(self::nodes($x->targets), $n->type); }

        if ($k === Node::KIND_TERNARY) {
            $x = self::asTernary($n);
            return new Ternary(self::node($x->cond), $x->then === null ? null : self::node($x->then), self::node($x->else_), $n->type);
        }
        if ($k === Node::KIND_STATIC_LOCAL_DECL) {
            $x = self::asStaticLocalDecl($n);
            return new StaticLocalDecl_($x->name, $x->cell, $x->guard, $x->init === null ? null : self::node($x->init), $n->type);
        }
        if ($k === Node::KIND_YIELD) {
            $x = self::asYield($n);
            return new Yield_($x->key === null ? null : self::node($x->key), $x->value === null ? null : self::node($x->value), $x->from, $n->type);
        }

        // ── Containers / arrays ───────────────────────────────────
        if ($k === Node::KIND_ARRAY_LIT) {
            $x = self::asArrayLit($n);
            $els = [];
            foreach ($x->elements as $el) {
                $els[] = new ArrayElement_($el->key === null ? null : self::node($el->key), self::node($el->value));
            }
            return new ArrayLit($els, $n->type);
        }
        if ($k === Node::KIND_ARRAY_ACCESS) { $x = self::asArrayAccess($n); return new ArrayAccess_(self::node($x->array), self::node($x->index), $n->type); }
        if ($k === Node::KIND_STORE_ELEMENT) { $x = self::asStoreElement($n); return new StoreElement(self::node($x->array), self::node($x->index), self::node($x->value), $n->type); }

        // ── Objects ───────────────────────────────────────────────
        if ($k === Node::KIND_NEW_OBJ) { $x = self::asNewObj($n); return new NewObj($x->class, self::nodes($x->args), $n->type); }
        if ($k === Node::KIND_NEW_DYN_OBJ) { $d = $n; return new NewDynObj(self::node($d->classExpr), self::nodes($d->args), $n->type); }
        if ($k === Node::KIND_PROPERTY_ACCESS) { $x = self::asPropertyAccess($n); return new PropertyAccess_(self::node($x->object), $x->property, $n->type); }
        if ($k === Node::KIND_STORE_PROPERTY) { $x = self::asStoreProperty($n); return new StoreProperty(self::node($x->object), $x->property, self::node($x->value), $n->type); }
        if ($k === Node::KIND_DYN_PROP) { $x = self::asDynProp($n); return new DynProp_(self::node($x->object), self::node($x->name), $n->type); }
        if ($k === Node::KIND_STORE_DYN_PROP) { $x = self::asStoreDynProp($n); return new StoreDynProp_(self::node($x->object), self::node($x->name), self::node($x->value), $n->type); }
        if ($k === Node::KIND_METHOD_CALL) { $x = self::asMethodCall($n); return new MethodCall_(self::node($x->object), $x->method, self::nodes($x->args), $n->type); }
        if ($k === Node::KIND_STATIC_CALL) { $x = self::asStaticCall($n); return new StaticCall_($x->class, $x->method, self::nodes($x->args), $n->type, $x->staticClass); }
        if ($k === Node::KIND_CLONE) {
            $x = self::asClone($n);
            $wp = [];
            foreach ($x->withProps as $pair) { $wp[] = new CloneWith($pair->name, self::node($pair->value)); }
            return new Clone_(self::node($x->object), $wp, $n->type);
        }

        // ── Control flow ──────────────────────────────────────────
        if ($k === Node::KIND_BLOCK) { $x = self::asBlock($n); return new Block(self::nodes($x->stmts), $n->type); }
        if ($k === Node::KIND_IF) {
            $x = self::asIf($n);
            return new If_(self::node($x->cond), self::asBlock(self::node($x->then)), $x->else === null ? null : self::asBlock(self::node($x->else)));
        }
        if ($k === Node::KIND_WHILE) { $x = self::asWhile($n); return new While_(self::node($x->cond), self::asBlock(self::node($x->body))); }
        if ($k === Node::KIND_DOWHILE) { $x = self::asDoWhile($n); return new DoWhile_(self::asBlock(self::node($x->body)), self::node($x->cond)); }
        if ($k === Node::KIND_FOR) {
            $x = self::asFor($n);
            return new For_(
                $x->init === null ? null : self::node($x->init),
                $x->cond === null ? null : self::node($x->cond),
                $x->step === null ? null : self::node($x->step),
                self::asBlock(self::node($x->body)),
            );
        }
        if ($k === Node::KIND_FOREACH) {
            $x = self::asForeach($n);
            return new Foreach_(self::node($x->array), $x->keyVar, $x->valueVar, $x->byRef, self::asBlock(self::node($x->body)));
        }
        if ($k === Node::KIND_SWITCH) {
            $x = self::asSwitch($n);
            $arms = [];
            foreach ($x->arms as $arm) {
                $arms[] = new SwitchArm_($arm->value === null ? null : self::node($arm->value), self::nodes($arm->body));
            }
            return new Switch_(self::node($x->subject), $arms);
        }
        if ($k === Node::KIND_MATCH) {
            $x = self::asMatch($n);
            $arms = [];
            foreach ($x->arms as $arm) {
                $conds = $arm->conds === null ? null : self::nodes($arm->conds);
                $arms[] = new MatchArm_($conds, self::node($arm->body));
            }
            return new Match_(self::node($x->subject), $arms, $n->type);
        }
        if ($k === Node::KIND_TRY_CATCH) {
            $x = self::asTryCatch($n);
            $catches = [];
            foreach ($x->catches as $c) {
                $catches[] = new MirCatch($c->types, $c->var, self::nodes($c->body));
            }
            return new TryCatch_(self::nodes($x->tryBody), $catches, self::nodes($x->finallyBody), $x->hasFinally, $n->type);
        }

        throw new \RuntimeException("NodeClone: unsupported node kind '" . $k . "'");
    }

    /**
     * @param Node[] $ns
     * @return Node[]
     */
    private static function nodes(array $ns): array
    {
        $out = [];
        foreach ($ns as $c) { $out[] = self::node($c); }
        return $out;
    }

    private static function asInt(Node $n): IntConst { return $n; }
    private static function asFloat(Node $n): FloatConst { return $n; }
    private static function asStr(Node $n): StringConst { return $n; }
    private static function asBool(Node $n): BoolConst { return $n; }
    private static function asLoadLocal(Node $n): LoadLocal { return $n; }
    private static function asStaticProp(Node $n): StaticProp_ { return $n; }
    private static function asBreak(Node $n): Break_ { return $n; }
    private static function asContinue(Node $n): Continue_ { return $n; }
    private static function asGoto(Node $n): \Compile\Mir\Goto_ { return $n; }
    private static function asLabel(Node $n): \Compile\Mir\Label_ { return $n; }
    private static function asIncDec(Node $n): IncDec { return $n; }
    private static function asRefAlias(Node $n): RefAlias_ { return $n; }
    private static function asAdd(Node $n): Add { return $n; }
    private static function asSub(Node $n): Sub { return $n; }
    private static function asMul(Node $n): Mul { return $n; }
    private static function asDiv(Node $n): Div { return $n; }
    private static function asMod(Node $n): Mod { return $n; }
    private static function asCmp(Node $n): Cmp { return $n; }
    private static function asConcat(Node $n): Concat { return $n; }
    private static function asBitOp(Node $n): BitOp { return $n; }
    private static function asNeg(Node $n): Neg { return $n; }
    private static function asNot(Node $n): Not_ { return $n; }
    private static function asBitNot(Node $n): BitNot_ { return $n; }
    private static function asCast(Node $n): Cast { return $n; }
    private static function asClassName(Node $n): ClassName_ { return $n; }
    private static function asInstanceof(Node $n): Instanceof_ { return $n; }
    private static function asEcho(Node $n): Echo_ { return $n; }
    private static function asReturn(Node $n): Return_ { return $n; }
    private static function asCall(Node $n): Call { return $n; }
    private static function asInvoke(Node $n): Invoke_ { return $n; }
    private static function asClosure(Node $n): Closure_ { return $n; }
    private static function asStoreLocal(Node $n): StoreLocal { return $n; }
    private static function asNullCoalesce(Node $n): NullCoalesce_ { return $n; }
    private static function asSpread(Node $n): Spread_ { return $n; }
    private static function asThrow(Node $n): Throw_ { return $n; }
    private static function asRefBind(Node $n): RefBind_ { return $n; }
    private static function asRefAddr(Node $n): RefAddr_ { return $n; }
    private static function asStoreStaticProp(Node $n): StoreStaticProp_ { return $n; }
    private static function asIsset(Node $n): Isset_ { return $n; }
    private static function asUnset(Node $n): Unset_ { return $n; }
    private static function asTernary(Node $n): Ternary { return $n; }
    private static function asStaticLocalDecl(Node $n): StaticLocalDecl_ { return $n; }
    private static function asYield(Node $n): Yield_ { return $n; }
    private static function asArrayLit(Node $n): ArrayLit { return $n; }
    private static function asArrayAccess(Node $n): ArrayAccess_ { return $n; }
    private static function asStoreElement(Node $n): StoreElement { return $n; }
    private static function asNewObj(Node $n): NewObj { return $n; }
    private static function asPropertyAccess(Node $n): PropertyAccess_ { return $n; }
    private static function asStoreProperty(Node $n): StoreProperty { return $n; }
    private static function asDynProp(Node $n): DynProp_ { return $n; }
    private static function asStoreDynProp(Node $n): StoreDynProp_ { return $n; }
    private static function asMethodCall(Node $n): MethodCall_ { return $n; }
    private static function asStaticCall(Node $n): StaticCall_ { return $n; }
    private static function asClone(Node $n): Clone_ { return $n; }
    private static function asBlock(Node $n): Block { return $n; }
    private static function asIf(Node $n): If_ { return $n; }
    private static function asWhile(Node $n): While_ { return $n; }
    private static function asDoWhile(Node $n): DoWhile_ { return $n; }
    private static function asFor(Node $n): For_ { return $n; }
    private static function asForeach(Node $n): Foreach_ { return $n; }
    private static function asSwitch(Node $n): Switch_ { return $n; }
    private static function asMatch(Node $n): Match_ { return $n; }
    private static function asTryCatch(Node $n): TryCatch_ { return $n; }
}
