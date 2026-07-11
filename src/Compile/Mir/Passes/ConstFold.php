<?php

namespace Compile\Mir\Passes;

use Compile\Mir\Add;
use Compile\Mir\ArrayAccess_;
use Compile\Mir\ArrayLit;
use Compile\Mir\Block;
use Compile\Mir\BoolConst;
use Compile\Mir\Concat;
use Compile\Mir\StringConst;
use Compile\Mir\Call;
use Compile\Mir\Closure_;
use Compile\Mir\Invoke_;
use Compile\Mir\NullCoalesce_;
use Compile\Mir\Instanceof_;
use Compile\Mir\Cast;
use Compile\Mir\Cmp;
use Compile\Mir\Div;
use Compile\Mir\Echo_;
use Compile\Mir\FloatConst;
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
use Compile\Mir\IntConst;
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
use Compile\Mir\Sub;
use Compile\Mir\Type;
use Compile\Mir\While_;

/**
 * Constant folding pass.
 *
 * Walks every function body bottom-up rewriting child references
 * when both operands of a pure arithmetic / comparison / unary
 * operation resolve to literals. `3 + 4` becomes `IntConst(7)`,
 * `5 > 0` becomes `BoolConst(true)`. Dead `if` branches whose
 * condition is now a known `BoolConst` collapse into a `Block`
 * holding only the taken branch's statements (or an empty Block
 * if the un-taken side has no body).
 *
 * Why bottom-up: parent foldNode delegates to a child foldNode
 * first, then inspects the returned node — if it's a constant,
 * the parent may itself reduce. That cascades naturally without
 * a separate fixed-point loop for simple chains.
 */
final class ConstFold implements Pass
{
    public const NAME = 'const-fold';

    public function name(): string { return self::NAME; }

    public function requires(): array { return [LowerFromAst::NAME]; }

    public function run(Module $module): Module
    {
        foreach ($module->functions as $fn) {
            $fn->body = $this->foldBlock($fn->body);
        }
        $module->markPassApplied(self::NAME);
        return $module;
    }

    private function foldNode(Node $n): Node
    {
        $kind = $n->kind;
        if ($kind === Node::KIND_ADD)         { return $this->foldAdd($n); }
        if ($kind === Node::KIND_SUB)         { return $this->foldSub($n); }
        if ($kind === Node::KIND_MUL)         { return $this->foldMul($n); }
        if ($kind === Node::KIND_DIV)         { return $this->foldDiv($n); }
        if ($kind === Node::KIND_MOD)         { return $this->foldMod($n); }
        if ($kind === Node::KIND_NEG)         { return $this->foldNeg($n); }
        if ($kind === Node::KIND_NOT)         { return $this->foldNot($n); }
        if ($kind === Node::KIND_CONCAT)      { return $this->foldConcat($n); }
        if ($kind === Node::KIND_CMP)         { return $this->foldCmp($n); }
        if ($kind === Node::KIND_CAST)        { return $this->foldCast($n); }
        if ($kind === Node::KIND_INSTANCEOF)  { return $this->foldInstanceof($n); }
        if ($kind === Node::KIND_NULLCOALESCE){ return $this->foldNullCoalesce($n); }
        if ($kind === Node::KIND_CLOSURE)     { return $this->foldClosure($n); }
        if ($kind === Node::KIND_INVOKE)      { return $this->foldInvoke($n); }
        if ($kind === Node::KIND_INCDEC)      { return $n; }
        if ($kind === Node::KIND_STATIC_PROP) { return $n; }
        if ($kind === Node::KIND_STORE_STATIC_PROP) { return $this->foldStoreStaticProp($n); }
        if ($kind === Node::KIND_STATIC_LOCAL_DECL) { return $this->foldStaticLocalDecl($n); }
        if ($kind === Node::KIND_ISSET) { return $this->foldIssetUnset($n); }
        if ($kind === Node::KIND_UNSET) { return $this->foldIssetUnset($n); }
        if ($kind === Node::KIND_CLASS_NAME) { return $n; }
        if ($kind === Node::KIND_REF_ALIAS) { return $n; }
        if ($kind === Node::KIND_THROW) { return $this->foldThrow($n); }
        if ($kind === Node::KIND_TRY_CATCH) { return $this->foldTryCatch($n); }
        if ($kind === Node::KIND_TERNARY)     { return $this->foldTernary($n); }
        if ($kind === Node::KIND_STORE_LOCAL) { return $this->foldStoreLocal($n); }
        if ($kind === Node::KIND_ECHO)        { return $this->foldEcho($n); }
        if ($kind === Node::KIND_RETURN)      { return $this->foldReturn($n); }
        if ($kind === Node::KIND_CALL)        { return $this->foldCall($n); }
        if ($kind === Node::KIND_IF)          { return $this->foldIf($n); }
        if ($kind === Node::KIND_WHILE)       { return $this->foldWhile($n); }
        if ($kind === Node::KIND_FOR)         { return $this->foldFor($n); }
        if ($kind === Node::KIND_DOWHILE)     { return $this->foldDoWhile($n); }
        if ($kind === Node::KIND_FOREACH)     { return $this->foldForeach($n); }
        if ($kind === Node::KIND_SWITCH)      { return $this->foldSwitch($n); }
        if ($kind === Node::KIND_MATCH)       { return $this->foldMatch($n); }
        if ($kind === Node::KIND_BLOCK)       { return $this->foldBlock($n); }
        if ($kind === Node::KIND_ARRAY_LIT)       { return $this->foldArrayLit($n); }
        if ($kind === Node::KIND_ARRAY_ACCESS)    { return $this->foldArrayAccess($n); }
        if ($kind === Node::KIND_STORE_ELEMENT)   { return $this->foldStoreElement($n); }
        if ($kind === Node::KIND_NEW_OBJ)         { return $this->foldNewObj($n); }
        if ($kind === Node::KIND_PROPERTY_ACCESS) { return $this->foldPropertyAccess($n); }
        if ($kind === Node::KIND_STORE_PROPERTY)  { return $this->foldStoreProperty($n); }
        if ($kind === Node::KIND_METHOD_CALL)     { return $this->foldMethodCall($n); }
        if ($kind === Node::KIND_STATIC_CALL)     { return $this->foldStaticCall($n); }
        return $n;
    }

    private function foldStoreStaticProp(StoreStaticProp_ $n): Node
    {
        $n->value = $this->foldNode($n->value);
        return $n;
    }

    private function foldStaticLocalDecl(StaticLocalDecl_ $n): Node
    {
        if ($n->init !== null) { $n->init = $this->foldNode($n->init); }
        return $n;
    }

    private function foldIssetUnset(Node $n): Node
    {
        // Targets are addressing nodes (load/array-access/prop); fold
        // their sub-expressions (indices) but keep the node shape.
        // $n narrows to Isset_/Unset_ from each guard (InferTypes kind-narrowing);
        // both carry `targets` — no cast pin needed.
        if ($n->kind === Node::KIND_ISSET) {
            $out = [];
            foreach ($n->targets as $t) { $out[] = $this->foldNode($t); }
            $n->targets = $out;
            return $n;
        }
        if ($n->kind === Node::KIND_UNSET) {
            $out = [];
            foreach ($n->targets as $t) { $out[] = $this->foldNode($t); }
            $n->targets = $out;
            return $n;
        }
        return $n;
    }

    private function foldThrow(Throw_ $t): Node
    {
        $t->value = $this->foldNode($t->value);
        return $t;
    }

    private function foldTryCatch(TryCatch_ $tc): Node
    {
        $tb = [];
        foreach ($tc->tryBody as $s) { $tb[] = $this->foldNode($s); }
        $tc->tryBody = $tb;
        foreach ($tc->catches as $c) {
            $cb = [];
            foreach ($c->body as $s) { $cb[] = $this->foldNode($s); }
            $c->body = $cb;
        }
        $fb = [];
        foreach ($tc->finallyBody as $s) { $fb[] = $this->foldNode($s); }
        $tc->finallyBody = $fb;
        return $tc;
    }

    private function foldArrayLit(ArrayLit $n): Node
    {
        foreach ($n->elements as $el) {
            if ($el->key !== null) { $el->key = $this->foldNode($el->key); }
            $el->value = $this->foldNode($el->value);
        }
        return $n;
    }

    private function foldArrayAccess(ArrayAccess_ $n): Node
    {
        $n->array = $this->foldNode($n->array);
        $n->index = $this->foldNode($n->index);
        return $n;
    }

    private function foldStoreElement(StoreElement $n): Node
    {
        $n->array = $this->foldNode($n->array);
        $n->index = $this->foldNode($n->index);
        $n->value = $this->foldNode($n->value);
        return $n;
    }

    private function foldNewObj(NewObj $n): Node
    {
        $out = [];
        foreach ($n->args as $a) { $out[] = $this->foldNode($a); }
        $n->args = $out;
        return $n;
    }

    private function foldPropertyAccess(PropertyAccess_ $n): Node
    {
        $n->object = $this->foldNode($n->object);
        return $n;
    }

    private function foldStoreProperty(StoreProperty $n): Node
    {
        $n->object = $this->foldNode($n->object);
        $n->value = $this->foldNode($n->value);
        return $n;
    }

    private function foldMethodCall(MethodCall_ $n): Node
    {
        $n->object = $this->foldNode($n->object);
        $out = [];
        foreach ($n->args as $a) { $out[] = $this->foldNode($a); }
        $n->args = $out;
        return $n;
    }

    private function foldStaticCall(StaticCall_ $n): Node
    {
        $out = [];
        foreach ($n->args as $a) { $out[] = $this->foldNode($a); }
        $n->args = $out;
        return $n;
    }

    private function foldStoreLocal(StoreLocal $n): Node
    {
        $n->value = $this->foldNode($n->value);
        $n->type = $n->value->type;
        return $n;
    }

    private function foldEcho(Echo_ $n): Node
    {
        $out = [];
        foreach ($n->exprs as $e) {
            $out[] = $this->foldNode($e);
        }
        $n->exprs = $out;
        return $n;
    }

    private function foldReturn(Return_ $n): Node
    {
        $v = $n->value;
        if ($v !== null) {
            $n->value = $this->foldNode($v);
        }
        return $n;
    }

    private function foldCall(Call $n): Node
    {
        $out = [];
        foreach ($n->args as $a) {
            $out[] = $this->foldNode($a);
        }
        $n->args = $out;
        return $n;
    }

    private function foldBlock(Block $n): Block
    {
        $out = [];
        foreach ($n->stmts as $s) {
            $out[] = $this->foldNode($s);
        }
        $n->stmts = $out;
        return $n;
    }

    private function foldWhile(While_ $n): Node
    {
        $n->cond = $this->foldNode($n->cond);
        $n->body = $this->foldBlock($n->body);
        return $n;
    }

    private function foldForeach(Foreach_ $n): Node
    {
        $n->array = $this->foldNode($n->array);
        $n->body = $this->foldBlock($n->body);
        return $n;
    }

    private function foldSwitch(Switch_ $n): Node
    {
        $n->subject = $this->foldNode($n->subject);
        foreach ($n->arms as $arm) {
            if ($arm->value !== null) { $arm->value = $this->foldNode($arm->value); }
            $body = [];
            foreach ($arm->body as $s) { $body[] = $this->foldNode($s); }
            $arm->body = $body;
        }
        return $n;
    }

    private function foldMatch(Match_ $n): Node
    {
        $n->subject = $this->foldNode($n->subject);
        foreach ($n->arms as $arm) {
            $conds = $arm->conds;
            if ($conds !== null) {
                $out = [];
                foreach ($conds as $c) { $out[] = $this->foldNode($c); }
                $arm->conds = $out;
            }
            $arm->body = $this->foldNode($arm->body);
        }
        return $n;
    }

    private function foldCast(Cast $n): Node
    {
        $n->operand = $this->foldNode($n->operand);
        return $n;
    }

    private function foldInstanceof(Instanceof_ $n): Node
    {
        $n->operand = $this->foldNode($n->operand);
        return $n;
    }

    private function foldClosure(Closure_ $n): Node
    {
        $out = [];
        foreach ($n->captures as $c) { $out[] = $this->foldNode($c); }
        $n->captures = $out;
        return $n;
    }

    private function foldInvoke(Invoke_ $n): Node
    {
        $n->callee = $this->foldNode($n->callee);
        $out = [];
        foreach ($n->args as $a) { $out[] = $this->foldNode($a); }
        $n->args = $out;
        return $n;
    }

    private function foldNullCoalesce(NullCoalesce_ $n): Node
    {
        $n->left = $this->foldNode($n->left);
        $n->right = $this->foldNode($n->right);
        return $n;
    }

    private function foldTernary(Ternary $n): Node
    {
        $n->cond = $this->foldNode($n->cond);
        if ($n->then !== null) { $n->then = $this->foldNode($n->then); }
        $n->else_ = $this->foldNode($n->else_);
        return $n;
    }

    private function foldFor(For_ $n): Node
    {
        if ($n->init !== null) { $n->init = $this->foldNode($n->init); }
        if ($n->cond !== null) { $n->cond = $this->foldNode($n->cond); }
        if ($n->step !== null) { $n->step = $this->foldNode($n->step); }
        $n->body = $this->foldBlock($n->body);
        return $n;
    }

    private function foldDoWhile(DoWhile_ $n): Node
    {
        $n->body = $this->foldBlock($n->body);
        $n->cond = $this->foldNode($n->cond);
        return $n;
    }

    /**
     * If the condition folds to a known boolean, replace the `if`
     * with a `Block` holding only the live branch's statements.
     * The dead branch is dropped entirely — the dump shows the
     * pruning happened by reading `; passes: …, const-fold` at
     * the top.
     */
    private function foldIf(If_ $n): Node
    {
        $n->cond = $this->foldNode($n->cond);
        $n->then = $this->foldBlock($n->then);
        if ($n->else !== null) {
            $n->else = $this->foldBlock($n->else);
        }
        if ($n->cond->kind === Node::KIND_BOOL_CONST) {
            $val = $n->cond->value;
            if ($val) {
                return $n->then;
            }
            if ($n->else !== null) {
                return $n->else;
            }
            return new Block([], Type::void());
        }
        return $n;
    }

    // ── Arithmetic ──────────────────────────────────────────────

    private function foldAdd(Add $n): Node
    {
        $n->left  = $this->foldNode($n->left);
        $n->right = $this->foldNode($n->right);
        if ($n->left->kind === Node::KIND_INT_CONST && $n->right->kind === Node::KIND_INT_CONST) {
            $l = $n->left->value;
            $r = $n->right->value;
            return new IntConst($l + $r, Type::int_());
        }
        return $n;
    }

    private function foldSub(Sub $n): Node
    {
        $n->left  = $this->foldNode($n->left);
        $n->right = $this->foldNode($n->right);
        if ($n->left->kind === Node::KIND_INT_CONST && $n->right->kind === Node::KIND_INT_CONST) {
            $l = $n->left->value;
            $r = $n->right->value;
            return new IntConst($l - $r, Type::int_());
        }
        return $n;
    }

    private function foldMul(Mul $n): Node
    {
        $n->left  = $this->foldNode($n->left);
        $n->right = $this->foldNode($n->right);
        if ($n->left->kind === Node::KIND_INT_CONST && $n->right->kind === Node::KIND_INT_CONST) {
            $l = $n->left->value;
            $r = $n->right->value;
            return new IntConst($l * $r, Type::int_());
        }
        return $n;
    }

    private function foldDiv(Div $n): Node
    {
        $n->left  = $this->foldNode($n->left);
        $n->right = $this->foldNode($n->right);
        // PHP `/` of two ints is an INT when evenly divisible (`6 / 2` →
        // int(3)), else a float. Fold only the exact case to an IntConst — a
        // non-exact `7 / 2` already evaluates to the right float at runtime, and
        // folding it to a FloatConst would hit the self-host's mistyped
        // FloatConst pre-scan. A zero divisor is left to the runtime.
        if ($n->left->kind === Node::KIND_INT_CONST && $n->right->kind === Node::KIND_INT_CONST) {
            $l = $n->left->value;
            $r = $n->right->value;
            if ($r !== 0 && $l % $r === 0) {
                return new IntConst((int)($l / $r), Type::int_());
            }
        }
        return $n;
    }

    private function foldMod(Mod $n): Node
    {
        $n->left  = $this->foldNode($n->left);
        $n->right = $this->foldNode($n->right);
        if ($n->left->kind === Node::KIND_INT_CONST && $n->right->kind === Node::KIND_INT_CONST) {
            $r = $n->right->value;
            if ($r === 0) { return $n; }
            $l = $n->left->value;
            return new IntConst($l % $r, Type::int_());
        }
        return $n;
    }

    // ── Unary ──────────────────────────────────────────────────

    private function foldNeg(Neg $n): Node
    {
        $n->operand = $this->foldNode($n->operand);
        if ($n->operand->kind === Node::KIND_INT_CONST) {
            $v = $n->operand->value;
            return new IntConst(-$v, Type::int_());
        }
        return $n;
    }

    private function foldNot(Not_ $n): Node
    {
        $n->operand = $this->foldNode($n->operand);
        if ($n->operand->kind === Node::KIND_BOOL_CONST) {
            $v = $n->operand->value;
            return new BoolConst(!$v, Type::bool_());
        }
        return $n;
    }

    private function foldConcat(Concat $n): Node
    {
        $n->left = $this->foldNode($n->left);
        $n->right = $this->foldNode($n->right);
        // Fold two string literals into one. Int/other operands stay
        // for the emitter to coerce at runtime.
        if ($n->left->kind === Node::KIND_STRING_CONST
            && $n->right->kind === Node::KIND_STRING_CONST) {
            $l = $n->left->value;
            $r = $n->right->value;
            return new StringConst($l . $r, Type::string_());
        }
        return $n;
    }

    // ── Comparison ─────────────────────────────────────────────

    private function foldCmp(Cmp $n): Node
    {
        $n->left  = $this->foldNode($n->left);
        $n->right = $this->foldNode($n->right);
        if ($n->left->kind === Node::KIND_INT_CONST && $n->right->kind === Node::KIND_INT_CONST) {
            $l = $n->left->value;
            $r = $n->right->value;
            return new BoolConst($this->cmpInt($n->op, $l, $r), Type::bool_());
        }
        return $n;
    }

    private function cmpInt(string $op, int $l, int $r): bool
    {
        if ($op === '==' || $op === '===') { return $l === $r; }
        if ($op === '!=' || $op === '!==') { return $l !== $r; }
        if ($op === '<')  { return $l < $r; }
        if ($op === '<=') { return $l <= $r; }
        if ($op === '>')  { return $l > $r; }
        if ($op === '>=') { return $l >= $r; }
        return false;
    }

}
