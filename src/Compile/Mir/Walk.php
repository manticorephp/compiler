<?php

namespace Compile\Mir;

/**
 * Structural child iterator for MIR nodes — the single source of
 * truth for "what are the sub-nodes of this node". Both {@see
 * Passes\Verify} and {@see Passes\InferEffects} recurse through it,
 * so a new node kind only needs wiring here once.
 *
 * Returns *value/control* children only (the things a generic walk
 * should descend into). Leaf payloads (literal values, op strings,
 * names) are not nodes and are omitted. Definition-site semantics
 * (which names a node *binds*) stay in the callers, since that is
 * pass-specific.
 */
final class Walk
{
    /** @return Node[] */
    public static function children(Node $n): array
    {
        $k = $n->kind;
        if ($k === Node::KIND_ADD || $k === Node::KIND_SUB || $k === Node::KIND_MUL
            || $k === Node::KIND_DIV || $k === Node::KIND_MOD || $k === Node::KIND_CMP
            || $k === Node::KIND_CONCAT) {
            return [self::binLeft($n), self::binRight($n)];
        }
        if ($k === Node::KIND_NEG)  { return [self::asNeg($n)->operand]; }
        if ($k === Node::KIND_NOT)  { return [self::asNot($n)->operand]; }
        if ($k === Node::KIND_BITOP) { $bb = self::asBitOp($n); return [$bb->left, $bb->right]; }
        if ($k === Node::KIND_BITNOT) { return [self::asBitNot($n)->operand]; }
        if ($k === Node::KIND_CAST) { return [self::asCast($n)->operand]; }
        if ($k === Node::KIND_INSTANCEOF) { return [self::asInstanceof($n)->operand]; }
        if ($k === Node::KIND_CLASS_NAME) { return [self::asClassName($n)->operand]; }
        if ($k === Node::KIND_NULLCOALESCE) {
            $nc = self::asNullCoalesce($n);
            return [$nc->left, $nc->right];
        }
        if ($k === Node::KIND_SPREAD) { return [self::asSpread($n)->operand]; }
        if ($k === Node::KIND_CLOSURE) { return self::asClosure($n)->captures; }
        if ($k === Node::KIND_INVOKE) {
            $iv = self::asInvoke($n);
            $out = [$iv->callee];
            foreach ($iv->args as $a) { $out[] = $a; }
            return $out;
        }
        if ($k === Node::KIND_STORE_STATIC_PROP) { return [self::asStoreStaticProp($n)->value]; }
        if ($k === Node::KIND_ISSET) { return self::asIsset($n)->targets; }
        if ($k === Node::KIND_UNSET) { return self::asUnset($n)->targets; }
        if ($k === Node::KIND_THROW) { return [self::asThrow($n)->value]; }
        if ($k === Node::KIND_YIELD) {
            $y = self::asYield($n);
            $out = [];
            if ($y->key !== null) { $out[] = $y->key; }
            if ($y->value !== null) { $out[] = $y->value; }
            return $out;
        }
        if ($k === Node::KIND_TERNARY) {
            $t = self::asTernary($n);
            $out = [$t->cond, $t->else_];
            if ($t->then !== null) { $out[] = $t->then; }
            return $out;
        }
        if ($k === Node::KIND_ECHO) { return self::asEcho($n)->exprs; }
        if ($k === Node::KIND_RETURN) {
            $v = self::asReturn($n)->value;
            return $v === null ? [] : [$v];
        }
        if ($k === Node::KIND_CALL) { return self::asCall($n)->args; }
        if ($k === Node::KIND_STORE_LOCAL) { return [self::asStoreLocal($n)->value]; }
        if ($k === Node::KIND_REF_BIND) { return [self::asRefBind($n)->call]; }
        if ($k === Node::KIND_REF_ADDR) { return [self::asRefAddr($n)->lvalue]; }
        if ($k === Node::KIND_STATIC_LOCAL_DECL) {
            $sld = self::asStaticLocalDecl($n);
            return $sld->init === null ? [] : [$sld->init];
        }
        if ($k === Node::KIND_IF) {
            $i = self::asIf($n);
            $out = [$i->cond, $i->then];
            if ($i->else !== null) { $out[] = $i->else; }
            return $out;
        }
        if ($k === Node::KIND_WHILE) {
            $w = self::asWhile($n);
            return [$w->cond, $w->body];
        }
        if ($k === Node::KIND_FOR) {
            $f = self::asFor($n);
            $out = [];
            if ($f->init !== null) { $out[] = $f->init; }
            if ($f->cond !== null) { $out[] = $f->cond; }
            if ($f->step !== null) { $out[] = $f->step; }
            $out[] = $f->body;
            return $out;
        }
        if ($k === Node::KIND_DOWHILE) {
            $d = self::asDoWhile($n);
            return [$d->body, $d->cond];
        }
        if ($k === Node::KIND_FOREACH) {
            $fe = self::asForeach($n);
            return [$fe->array, $fe->body];
        }
        if ($k === Node::KIND_TRY_CATCH) {
            $tc = self::asTryCatch($n);
            $out = [];
            foreach ($tc->tryBody as $s) { $out[] = $s; }
            foreach ($tc->catches as $c) {
                foreach ($c->body as $s) { $out[] = $s; }
            }
            foreach ($tc->finallyBody as $s) { $out[] = $s; }
            return $out;
        }
        if ($k === Node::KIND_SWITCH) {
            $sw = self::asSwitch($n);
            $out = [$sw->subject];
            foreach ($sw->arms as $arm) {
                if ($arm->value !== null) { $out[] = $arm->value; }
                foreach ($arm->body as $s) { $out[] = $s; }
            }
            return $out;
        }
        if ($k === Node::KIND_MATCH) {
            $m = self::asMatch($n);
            $out = [$m->subject];
            foreach ($m->arms as $arm) {
                $conds = $arm->conds;
                if ($conds !== null) {
                    foreach ($conds as $c) { $out[] = $c; }
                }
                $out[] = $arm->body;
            }
            return $out;
        }
        if ($k === Node::KIND_BLOCK) { return self::asBlock($n)->stmts; }
        if ($k === Node::KIND_ARRAY_LIT) {
            $out = [];
            foreach (self::asArrayLit($n)->elements as $el) {
                if ($el->key !== null) { $out[] = $el->key; }
                $out[] = $el->value;
            }
            return $out;
        }
        if ($k === Node::KIND_ARRAY_ACCESS) {
            $a = self::asArrayAccess($n);
            return [$a->array, $a->index];
        }
        if ($k === Node::KIND_STORE_ELEMENT) {
            $se = self::asStoreElement($n);
            return [$se->array, $se->index, $se->value];
        }
        if ($k === Node::KIND_NEW_OBJ) { return self::asNewObj($n)->args; }
        if ($k === Node::KIND_CLONE) {
            $cl = self::asClone($n);
            $out = [$cl->object];
            foreach ($cl->withProps as $pair) { $out[] = $pair->value; }
            return $out;
        }
        if ($k === Node::KIND_PROPERTY_ACCESS) { return [self::asPropertyAccess($n)->object]; }
        if ($k === Node::KIND_STORE_PROPERTY) {
            $sp = self::asStoreProperty($n);
            return [$sp->object, $sp->value];
        }
        if ($k === Node::KIND_DYN_PROP) {
            $dp = self::asDynProp($n);
            return [$dp->object, $dp->name];
        }
        if ($k === Node::KIND_STORE_DYN_PROP) {
            $sd = self::asStoreDynProp($n);
            return [$sd->object, $sd->name, $sd->value];
        }
        if ($k === Node::KIND_METHOD_CALL) {
            $mc = self::asMethodCall($n);
            $out = [$mc->object];
            foreach ($mc->args as $a) { $out[] = $a; }
            return $out;
        }
        if ($k === Node::KIND_STATIC_CALL) { return self::asStaticCall($n)->args; }
        if ($k === Node::KIND_MEMORY_OP) {
            $t = self::asMemoryOp($n)->target;
            return $t === null ? [] : [$t];
        }
        return [];
    }

    private static function binLeft(Node $n): Node { return $n->left; }
    private static function binRight(Node $n): Node { return $n->right; }
    private static function asNeg(Node $n): Neg { return $n; }
    private static function asNot(Node $n): Not_ { return $n; }
    private static function asBitOp(Node $n): BitOp { return $n; }
    private static function asBitNot(Node $n): BitNot_ { return $n; }
    private static function asCast(Node $n): Cast { return $n; }
    private static function asInstanceof(Node $n): Instanceof_ { return $n; }
    private static function asClassName(Node $n): ClassName_ { return $n; }
    private static function asNullCoalesce(Node $n): NullCoalesce_ { return $n; }
    private static function asSpread(Node $n): Spread_ { return $n; }
    private static function asClosure(Node $n): Closure_ { return $n; }
    private static function asInvoke(Node $n): Invoke_ { return $n; }
    private static function asStoreStaticProp(Node $n): StoreStaticProp_ { return $n; }
    private static function asIsset(Node $n): Isset_ { return $n; }
    private static function asUnset(Node $n): Unset_ { return $n; }
    private static function asThrow(Node $n): Throw_ { return $n; }
    private static function asYield(Node $n): Yield_ { return $n; }
    private static function asTernary(Node $n): Ternary { return $n; }
    private static function asEcho(Node $n): Echo_ { return $n; }
    private static function asReturn(Node $n): Return_ { return $n; }
    private static function asCall(Node $n): Call { return $n; }
    private static function asStoreLocal(Node $n): StoreLocal { return $n; }
    private static function asRefBind(Node $n): RefBind_ { return $n; }
    private static function asRefAddr(Node $n): RefAddr_ { return $n; }
    private static function asStaticLocalDecl(Node $n): StaticLocalDecl_ { return $n; }
    private static function asIf(Node $n): If_ { return $n; }
    private static function asWhile(Node $n): While_ { return $n; }
    private static function asFor(Node $n): For_ { return $n; }
    private static function asDoWhile(Node $n): DoWhile_ { return $n; }
    private static function asForeach(Node $n): Foreach_ { return $n; }
    private static function asTryCatch(Node $n): TryCatch_ { return $n; }
    private static function asSwitch(Node $n): Switch_ { return $n; }
    private static function asMatch(Node $n): Match_ { return $n; }
    private static function asBlock(Node $n): Block { return $n; }
    private static function asArrayLit(Node $n): ArrayLit { return $n; }
    private static function asArrayAccess(Node $n): ArrayAccess_ { return $n; }
    private static function asStoreElement(Node $n): StoreElement { return $n; }
    private static function asNewObj(Node $n): NewObj { return $n; }
    private static function asClone(Node $n): Clone_ { return $n; }
    private static function asPropertyAccess(Node $n): PropertyAccess_ { return $n; }
    private static function asStoreProperty(Node $n): StoreProperty { return $n; }
    private static function asDynProp(Node $n): DynProp_ { return $n; }
    private static function asStoreDynProp(Node $n): StoreDynProp_ { return $n; }
    private static function asMethodCall(Node $n): MethodCall_ { return $n; }
    private static function asStaticCall(Node $n): StaticCall_ { return $n; }
    private static function asMemoryOp(Node $n): MemoryOp_ { return $n; }
}
