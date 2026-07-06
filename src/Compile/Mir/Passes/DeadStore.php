<?php

namespace Compile\Mir\Passes;

use Compile\Mir\Add;
use Compile\Mir\ArrayAccess_;
use Compile\Mir\ArrayLit;
use Compile\Mir\Block;
use Compile\Mir\Call;
use Compile\Mir\Closure_;
use Compile\Mir\Invoke_;
use Compile\Mir\NullCoalesce_;
use Compile\Mir\Instanceof_;
use Compile\Mir\Cast;
use Compile\Mir\Cmp;
use Compile\Mir\Concat;
use Compile\Mir\Div;
use Compile\Mir\Echo_;
use Compile\Mir\FunctionDef;
use Compile\Mir\Foreach_;
use Compile\Mir\For_;
use Compile\Mir\DoWhile_;
use Compile\Mir\IncDec;
use Compile\Mir\StaticProp_;
use Compile\Mir\StoreStaticProp_;
use Compile\Mir\StaticLocalDecl_;
use Compile\Mir\Isset_;
use Compile\Mir\Unset_;
use Compile\Mir\ClassName_;
use Compile\Mir\RefAlias_;
use Compile\Mir\Throw_;
use Compile\Mir\TryCatch_;
use Compile\Mir\MirCatch;
use Compile\Mir\Ternary;
use Compile\Mir\Switch_;
use Compile\Mir\SwitchArm_;
use Compile\Mir\Match_;
use Compile\Mir\MatchArm_;
use Compile\Mir\If_;
use Compile\Mir\LoadLocal;
use Compile\Mir\MethodCall_;
use Compile\Mir\Mod;
use Compile\Mir\Module;
use Compile\Mir\Mul;
use Compile\Mir\Neg;
use Compile\Mir\NewObj;
use Compile\Mir\Node;
use Compile\Mir\Not_;
use Compile\Mir\Pass;
use Compile\Mir\PropertyAccess_;
use Compile\Mir\Return_;
use Compile\Mir\StaticCall_;
use Compile\Mir\StoreElement;
use Compile\Mir\StoreLocal;
use Compile\Mir\StoreProperty;
use Compile\Mir\DynProp_;
use Compile\Mir\StoreDynProp_;
use Compile\Mir\Sub;
use Compile\Mir\While_;

/**
 * Dead-Store Elimination.
 *
 * Two-pass per function:
 *   1. Walk every node, collect the set of locals that are ever
 *      read via `LoadLocal`. Includes reads inside arbitrarily
 *      nested control flow.
 *   2. Walk every `Block` and drop any `StoreLocal` whose name
 *      isn't in the used set AND whose value expression is pure
 *      (no `Call`). Side-effecting RHS is kept so its effect
 *      isn't lost.
 *
 * Flow-insensitive — a single use anywhere in the function keeps
 * every store live. Killing-store overwrites (`$x = 1; $x = 2;`
 * where the first dies) needs a CFG + per-block liveness; that
 * lands in a later pass once the structured CFG is also lowered
 * to basic blocks.
 *
 * Why we conservatively keep stores with `Call`-bearing RHS even
 * when the local is unused: a `$_ = sideEffectingFn();` pattern
 * exists in real code and dropping it would silently change
 * program semantics.
 */
final class DeadStore implements Pass
{
    public const NAME = 'dead-store';

    public function name(): string { return self::NAME; }

    public function requires(): array { return [LowerFromAst::NAME]; }

    /** @var array<string, true> */
    private array $usedLocals = [];

    public function run(Module $module): Module
    {
        foreach ($module->functions as $fn) {
            $this->usedLocals = [];
            // A store to a by-ref param (incl. a closure's `use (&$x)` capture,
            // lowered to a byRef param) is observable by the caller, so it is
            // never dead even with no in-function read. Seed it as "used".
            foreach ($fn->params as $p) {
                if ($p->byRef) { $this->usedLocals[$p->name] = true; }
            }
            $this->collectUses($fn->body);
            $fn->body = $this->rewriteBlock($fn->body);
        }
        $module->markPassApplied(self::NAME);
        return $module;
    }

    // ── Pass 1: gather every loaded local name ─────────────────

    private function collectUses(Node $n): void
    {
        $k = $n->kind;
        if ($k === Node::KIND_LOAD_LOCAL) {
            $this->usedLocals[$this->asLoadLocal($n)->name] = true;
            return;
        }
        if ($k === Node::KIND_STORE_LOCAL) { $this->collectUses($this->asStoreLocal($n)->value); return; }
        if ($k === Node::KIND_ADD) { $a = $this->asAdd($n); $this->collectUses($a->left); $this->collectUses($a->right); return; }
        if ($k === Node::KIND_SUB) { $a = $this->asSub($n); $this->collectUses($a->left); $this->collectUses($a->right); return; }
        if ($k === Node::KIND_MUL) { $a = $this->asMul($n); $this->collectUses($a->left); $this->collectUses($a->right); return; }
        if ($k === Node::KIND_DIV) { $a = $this->asDiv($n); $this->collectUses($a->left); $this->collectUses($a->right); return; }
        if ($k === Node::KIND_MOD) { $a = $this->asMod($n); $this->collectUses($a->left); $this->collectUses($a->right); return; }
        if ($k === Node::KIND_NEG) { $this->collectUses($this->asNeg($n)->operand); return; }
        if ($k === Node::KIND_NOT) { $this->collectUses($this->asNot($n)->operand); return; }
        if ($k === Node::KIND_BITOP) { $b = $this->asBitOp($n); $this->collectUses($b->left); $this->collectUses($b->right); return; }
        if ($k === Node::KIND_BITNOT) { $this->collectUses($this->asBitNot($n)->operand); return; }
        if ($k === Node::KIND_CONCAT) { $c = $this->asConcat($n); $this->collectUses($c->left); $this->collectUses($c->right); return; }
        if ($k === Node::KIND_CMP) { $c = $this->asCmp($n); $this->collectUses($c->left); $this->collectUses($c->right); return; }
        if ($k === Node::KIND_CAST) { $this->collectUses($this->asCast($n)->operand); return; }
        if ($k === Node::KIND_INSTANCEOF) { $this->collectUses($this->asInstanceof($n)->operand); return; }
        if ($k === Node::KIND_NULLCOALESCE) { $nc = $this->asNullCoalesce($n); $this->collectUses($nc->left); $this->collectUses($nc->right); return; }
        if ($k === Node::KIND_CLOSURE) { foreach ($this->asClosure($n)->captures as $c) { $this->collectUses($c); } return; }
        if ($k === Node::KIND_INVOKE) { $iv = $this->asInvoke($n); $this->collectUses($iv->callee); foreach ($iv->args as $a) { $this->collectUses($a); } return; }
        if ($k === Node::KIND_INCDEC) { $this->usedLocals[$this->asIncDec($n)->name] = true; return; }
        if ($k === Node::KIND_STATIC_PROP) { return; }
        if ($k === Node::KIND_STORE_STATIC_PROP) { $this->collectUses($this->asStoreStaticProp($n)->value); return; }
        if ($k === Node::KIND_STATIC_LOCAL_DECL) {
            $sld = $this->asStaticLocalDecl($n);
            $this->usedLocals[$sld->name] = true;
            if ($sld->init !== null) { $this->collectUses($sld->init); }
            return;
        }
        if ($k === Node::KIND_ISSET) {
            foreach ($this->asIsset($n)->targets as $t) { $this->collectUses($t); }
            return;
        }
        if ($k === Node::KIND_UNSET) {
            foreach ($this->asUnset($n)->targets as $t) { $this->collectUses($t); }
            return;
        }
        if ($k === Node::KIND_CLASS_NAME) { $this->collectUses($this->asClassName($n)->operand); return; }
        if ($k === Node::KIND_REF_ALIAS) {
            $ra = $this->asRefAlias($n);
            $this->usedLocals[$ra->target] = true;
            $this->usedLocals[$ra->source] = true;
            return;
        }
        if ($k === Node::KIND_REF_ADDR) {
            // The target aliases a container slot — a store to it writes THROUGH
            // to the property/element (an observable side effect), so it must
            // never be dead-store eliminated. Mark it used unconditionally.
            $ra = $this->asRefAddr($n);
            $this->usedLocals[$ra->target] = true;
            $this->collectUses($ra->lvalue);
            return;
        }
        if ($k === Node::KIND_THROW) { $this->collectUses($this->asThrow($n)->value); return; }
        if ($k === Node::KIND_TRY_CATCH) {
            $tc = $this->asTryCatch($n);
            foreach ($tc->tryBody as $s) { $this->collectUses($s); }
            foreach ($tc->catches as $c) {
                if ($c->var !== null) { $this->usedLocals[$c->var] = true; }
                foreach ($c->body as $s) { $this->collectUses($s); }
            }
            foreach ($tc->finallyBody as $s) { $this->collectUses($s); }
            return;
        }
        if ($k === Node::KIND_TERNARY) {
            $t = $this->asTernary($n);
            $this->collectUses($t->cond);
            if ($t->then !== null) { $this->collectUses($t->then); }
            $this->collectUses($t->else_);
            return;
        }
        if ($k === Node::KIND_ECHO) {
            foreach ($this->asEcho($n)->exprs as $e) { $this->collectUses($e); }
            return;
        }
        if ($k === Node::KIND_RETURN) {
            $v = $this->asReturn($n)->value;
            if ($v !== null) { $this->collectUses($v); }
            return;
        }
        if ($k === Node::KIND_CALL) {
            foreach ($this->asCall($n)->args as $a) { $this->collectUses($a); }
            return;
        }
        if ($k === Node::KIND_IF) {
            $i = $this->asIf($n);
            $this->collectUses($i->cond);
            $this->collectUses($i->then);
            if ($i->else !== null) { $this->collectUses($i->else); }
            return;
        }
        if ($k === Node::KIND_WHILE) {
            $w = $this->asWhile($n);
            $this->collectUses($w->cond);
            $this->collectUses($w->body);
            return;
        }
        if ($k === Node::KIND_FOR) {
            $f = $this->asFor($n);
            if ($f->init !== null) { $this->collectUses($f->init); }
            if ($f->cond !== null) { $this->collectUses($f->cond); }
            if ($f->step !== null) { $this->collectUses($f->step); }
            $this->collectUses($f->body);
            return;
        }
        if ($k === Node::KIND_DOWHILE) {
            $d = $this->asDoWhile($n);
            $this->collectUses($d->body);
            $this->collectUses($d->cond);
            return;
        }
        if ($k === Node::KIND_FOREACH) {
            $fe = $this->asForeach($n);
            $this->collectUses($fe->array);
            $this->collectUses($fe->body);
            return;
        }
        if ($k === Node::KIND_SWITCH) {
            $sw = $this->asSwitch($n);
            $this->collectUses($sw->subject);
            foreach ($sw->arms as $arm) {
                if ($arm->value !== null) { $this->collectUses($arm->value); }
                foreach ($arm->body as $s) { $this->collectUses($s); }
            }
            return;
        }
        if ($k === Node::KIND_MATCH) {
            $m = $this->asMatch($n);
            $this->collectUses($m->subject);
            foreach ($m->arms as $arm) {
                $conds = $arm->conds;
                if ($conds !== null) {
                    foreach ($conds as $c) { $this->collectUses($c); }
                }
                $this->collectUses($arm->body);
            }
            return;
        }
        if ($k === Node::KIND_BLOCK) {
            foreach ($this->asBlock($n)->stmts as $s) { $this->collectUses($s); }
            return;
        }
        if ($k === Node::KIND_ARRAY_LIT) {
            foreach ($this->asArrayLit($n)->elements as $el) {
                if ($el->key !== null) { $this->collectUses($el->key); }
                $this->collectUses($el->value);
            }
            return;
        }
        if ($k === Node::KIND_ARRAY_ACCESS) {
            $a = $this->asArrayAccess($n);
            $this->collectUses($a->array);
            $this->collectUses($a->index);
            return;
        }
        if ($k === Node::KIND_STORE_ELEMENT) {
            $se = $this->asStoreElement($n);
            $this->collectUses($se->array);
            $this->collectUses($se->index);
            $this->collectUses($se->value);
            return;
        }
        if ($k === Node::KIND_NEW_OBJ) {
            foreach ($this->asNewObj($n)->args as $a) { $this->collectUses($a); }
            return;
        }
        if ($k === Node::KIND_PROPERTY_ACCESS) {
            $this->collectUses($this->asPropertyAccess($n)->object);
            return;
        }
        if ($k === Node::KIND_STORE_PROPERTY) {
            $sp = $this->asStoreProperty($n);
            $this->collectUses($sp->object);
            $this->collectUses($sp->value);
            return;
        }
        if ($k === Node::KIND_DYN_PROP) {
            $dp = $this->asDynProp($n);
            $this->collectUses($dp->object);
            $this->collectUses($dp->name);
            return;
        }
        if ($k === Node::KIND_STORE_DYN_PROP) {
            $sd = $this->asStoreDynProp($n);
            $this->collectUses($sd->object);
            $this->collectUses($sd->name);
            $this->collectUses($sd->value);
            return;
        }
        if ($k === Node::KIND_METHOD_CALL) {
            $mc = $this->asMethodCall($n);
            $this->collectUses($mc->object);
            foreach ($mc->args as $a) { $this->collectUses($a); }
            return;
        }
        if ($k === Node::KIND_STATIC_CALL) {
            foreach ($this->asStaticCall($n)->args as $a) { $this->collectUses($a); }
            return;
        }
    }

    // ── Pass 2: drop unused pure stores ────────────────────────

    private function rewriteBlock(Block $b): Block
    {
        $out = [];
        foreach ($b->stmts as $s) {
            $kept = $this->rewriteStmt($s);
            if ($kept !== null) { $out[] = $kept; }
        }
        $b->stmts = $out;
        return $b;
    }

    private function rewriteStmt(Node $n): ?Node
    {
        $k = $n->kind;
        if ($k === Node::KIND_STORE_LOCAL) {
            $sl = $this->asStoreLocal($n);
            if (!isset($this->usedLocals[$sl->name]) && $this->isPure($sl->value)) {
                return null;
            }
            return $sl;
        }
        if ($k === Node::KIND_IF) {
            $i = $this->asIf($n);
            $i->then = $this->rewriteBlock($i->then);
            if ($i->else !== null) {
                $i->else = $this->rewriteBlock($i->else);
            }
            return $i;
        }
        if ($k === Node::KIND_WHILE) {
            $w = $this->asWhile($n);
            $w->body = $this->rewriteBlock($w->body);
            return $w;
        }
        if ($k === Node::KIND_FOR) {
            $f = $this->asFor($n);
            $f->body = $this->rewriteBlock($f->body);
            return $f;
        }
        if ($k === Node::KIND_DOWHILE) {
            $d = $this->asDoWhile($n);
            $d->body = $this->rewriteBlock($d->body);
            return $d;
        }
        if ($k === Node::KIND_BLOCK) {
            return $this->rewriteBlock($this->asBlock($n));
        }
        return $n;
    }

    /**
     * Conservative purity: a node is pure if it does not directly
     * call anything and all its children are pure. Reads of locals
     * are pure; arithmetic / cmp / unary on pure operands are pure.
     */
    private function isPure(Node $n): bool
    {
        $k = $n->kind;
        if ($k === Node::KIND_INT_CONST
            || $k === Node::KIND_FLOAT_CONST
            || $k === Node::KIND_STRING_CONST
            || $k === Node::KIND_BOOL_CONST
            || $k === Node::KIND_NULL_CONST
            || $k === Node::KIND_LOAD_LOCAL) {
            return true;
        }
        if ($k === Node::KIND_ADD) { $a = $this->asAdd($n); return $this->isPure($a->left) && $this->isPure($a->right); }
        if ($k === Node::KIND_SUB) { $a = $this->asSub($n); return $this->isPure($a->left) && $this->isPure($a->right); }
        if ($k === Node::KIND_MUL) { $a = $this->asMul($n); return $this->isPure($a->left) && $this->isPure($a->right); }
        if ($k === Node::KIND_DIV) { $a = $this->asDiv($n); return $this->isPure($a->left) && $this->isPure($a->right); }
        if ($k === Node::KIND_MOD) { $a = $this->asMod($n); return $this->isPure($a->left) && $this->isPure($a->right); }
        if ($k === Node::KIND_NEG) { return $this->isPure($this->asNeg($n)->operand); }
        if ($k === Node::KIND_NOT) { return $this->isPure($this->asNot($n)->operand); }
        if ($k === Node::KIND_BITOP) { $b = $this->asBitOp($n); return $this->isPure($b->left) && $this->isPure($b->right); }
        if ($k === Node::KIND_BITNOT) { return $this->isPure($this->asBitNot($n)->operand); }
        if ($k === Node::KIND_CONCAT) { $c = $this->asConcat($n); return $this->isPure($c->left) && $this->isPure($c->right); }
        if ($k === Node::KIND_CMP) { $c = $this->asCmp($n); return $this->isPure($c->left) && $this->isPure($c->right); }
        return false;
    }

    // ── Typed-cast helpers (see ConstFold for the rationale) ───

    private function asArrayLit(ArrayLit $n): ArrayLit { return $n; }
    private function asArrayAccess(ArrayAccess_ $n): ArrayAccess_ { return $n; }
    private function asStoreElement(StoreElement $n): StoreElement { return $n; }
    private function asNewObj(NewObj $n): NewObj { return $n; }
    private function asPropertyAccess(PropertyAccess_ $n): PropertyAccess_ { return $n; }
    private function asStoreProperty(StoreProperty $n): StoreProperty { return $n; }
    private function asDynProp(DynProp_ $n): DynProp_ { return $n; }
    private function asStoreDynProp(StoreDynProp_ $n): StoreDynProp_ { return $n; }
    private function asMethodCall(MethodCall_ $n): MethodCall_ { return $n; }
    private function asStaticCall(StaticCall_ $n): StaticCall_ { return $n; }
    private function asLoadLocal(LoadLocal $n): LoadLocal { return $n; }
    private function asStoreLocal(StoreLocal $n): StoreLocal { return $n; }
    private function asAdd(Add $n): Add { return $n; }
    private function asSub(Sub $n): Sub { return $n; }
    private function asMul(Mul $n): Mul { return $n; }
    private function asDiv(Div $n): Div { return $n; }
    private function asMod(Mod $n): Mod { return $n; }
    private function asNeg(Neg $n): Neg { return $n; }
    private function asNot(Not_ $n): Not_ { return $n; }
    private function asBitOp(Node $n): \Compile\Mir\BitOp { return $n; }
    private function asBitNot(Node $n): \Compile\Mir\BitNot_ { return $n; }
    private function asConcat(Concat $n): Concat { return $n; }
    private function asCmp(Cmp $n): Cmp { return $n; }
    private function asEcho(Echo_ $n): Echo_ { return $n; }
    private function asReturn(Return_ $n): Return_ { return $n; }
    private function asCall(Call $n): Call { return $n; }
    private function asIf(If_ $n): If_ { return $n; }
    private function asWhile(While_ $n): While_ { return $n; }
    private function asCast(Cast $n): Cast { return $n; }
    private function asInstanceof(Instanceof_ $n): Instanceof_ { return $n; }
    private function asNullCoalesce(NullCoalesce_ $n): NullCoalesce_ { return $n; }
    private function asClosure(Closure_ $n): Closure_ { return $n; }
    private function asInvoke(Invoke_ $n): Invoke_ { return $n; }
    private function asIncDec(IncDec $n): IncDec { return $n; }
    private function asStoreStaticProp(StoreStaticProp_ $n): StoreStaticProp_ { return $n; }
    private function asStaticLocalDecl(StaticLocalDecl_ $n): StaticLocalDecl_ { return $n; }
    private function asIsset(Isset_ $n): Isset_ { return $n; }
    private function asUnset(Unset_ $n): Unset_ { return $n; }
    private function asClassName(ClassName_ $n): ClassName_ { return $n; }
    private function asRefAlias(RefAlias_ $n): RefAlias_ { return $n; }
    private function asRefAddr(Node $n): \Compile\Mir\RefAddr_ { return $n; }
    private function asThrow(Throw_ $n): Throw_ { return $n; }
    private function asTryCatch(TryCatch_ $n): TryCatch_ { return $n; }
    private function asTernary(Ternary $n): Ternary { return $n; }
    private function asForeach(Foreach_ $n): Foreach_ { return $n; }
    private function asSwitch(Switch_ $n): Switch_ { return $n; }
    private function asMatch(Match_ $n): Match_ { return $n; }
    private function asFor(For_ $n): For_ { return $n; }
    private function asDoWhile(DoWhile_ $n): DoWhile_ { return $n; }
    private function asBlock(Block $n): Block { return $n; }
}
