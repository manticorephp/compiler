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
        if ($n->kind === Node::KIND_LOAD_LOCAL) {
            $this->usedLocals[$n->name] = true;
            return;
        }
        if ($n->kind === Node::KIND_STORE_LOCAL) { $this->collectUses($n->value); return; }
        if ($n->kind === Node::KIND_ADD) { $this->collectUses($n->left); $this->collectUses($n->right); return; }
        if ($n->kind === Node::KIND_SUB) { $this->collectUses($n->left); $this->collectUses($n->right); return; }
        if ($n->kind === Node::KIND_MUL) { $this->collectUses($n->left); $this->collectUses($n->right); return; }
        if ($n->kind === Node::KIND_DIV) { $this->collectUses($n->left); $this->collectUses($n->right); return; }
        if ($n->kind === Node::KIND_MOD) { $this->collectUses($n->left); $this->collectUses($n->right); return; }
        if ($n->kind === Node::KIND_NEG) { $this->collectUses($n->operand); return; }
        if ($n->kind === Node::KIND_NOT) { $this->collectUses($n->operand); return; }
        if ($n->kind === Node::KIND_BITOP) { $this->collectUses($n->left); $this->collectUses($n->right); return; }
        if ($n->kind === Node::KIND_BITNOT) { $this->collectUses($n->operand); return; }
        if ($n->kind === Node::KIND_CONCAT) { $this->collectUses($n->left); $this->collectUses($n->right); return; }
        if ($n->kind === Node::KIND_CMP) { $this->collectUses($n->left); $this->collectUses($n->right); return; }
        if ($n->kind === Node::KIND_CAST) { $this->collectUses($n->operand); return; }
        if ($n->kind === Node::KIND_INSTANCEOF) { $this->collectUses($n->operand); return; }
        if ($n->kind === Node::KIND_NULLCOALESCE) { $this->collectUses($n->left); $this->collectUses($n->right); return; }
        if ($n->kind === Node::KIND_CLOSURE) { foreach ($n->captures as $c) { $this->collectUses($c); } return; }
        if ($n->kind === Node::KIND_INVOKE) { $this->collectUses($n->callee); foreach ($n->args as $a) { $this->collectUses($a); } return; }
        if ($n->kind === Node::KIND_INCDEC) { $this->usedLocals[$n->name] = true; return; }
        if ($n->kind === Node::KIND_STATIC_PROP) { return; }
        if ($n->kind === Node::KIND_STORE_STATIC_PROP) { $this->collectUses($n->value); return; }
        if ($n->kind === Node::KIND_STATIC_LOCAL_DECL) {
            $this->usedLocals[$n->name] = true;
            if ($n->init !== null) { $this->collectUses($n->init); }
            return;
        }
        if ($n->kind === Node::KIND_ISSET) {
            foreach ($n->targets as $t) { $this->collectUses($t); }
            return;
        }
        if ($n->kind === Node::KIND_UNSET) {
            foreach ($n->targets as $t) { $this->collectUses($t); }
            return;
        }
        if ($n->kind === Node::KIND_CLASS_NAME) { $this->collectUses($n->operand); return; }
        if ($n->kind === Node::KIND_REF_ALIAS) {
            $this->usedLocals[$n->target] = true;
            $this->usedLocals[$n->source] = true;
            return;
        }
        if ($n->kind === Node::KIND_REF_ADDR) {
            // The target aliases a container slot — a store to it writes THROUGH
            // to the property/element (an observable side effect), so it must
            // never be dead-store eliminated. Mark it used unconditionally.
            $this->usedLocals[$n->target] = true;
            $this->collectUses($n->lvalue);
            return;
        }
        if ($n->kind === Node::KIND_REF_BIND) {
            // The target holds a by-ref return address; a store to it writes
            // THROUGH to the aliased slot (observable), so keep it used.
            $this->usedLocals[$n->target] = true;
            $this->collectUses($n->call);
            return;
        }
        if ($n->kind === Node::KIND_THROW) { $this->collectUses($n->value); return; }
        if ($n->kind === Node::KIND_TRY_CATCH) {
            foreach ($n->tryBody as $s) { $this->collectUses($s); }
            foreach ($n->catches as $c) {
                if ($c->var !== null) { $this->usedLocals[$c->var] = true; }
                foreach ($c->body as $s) { $this->collectUses($s); }
            }
            foreach ($n->finallyBody as $s) { $this->collectUses($s); }
            return;
        }
        if ($n->kind === Node::KIND_TERNARY) {
            $this->collectUses($n->cond);
            if ($n->then !== null) { $this->collectUses($n->then); }
            $this->collectUses($n->else_);
            return;
        }
        if ($n->kind === Node::KIND_ECHO) {
            foreach ($n->exprs as $e) { $this->collectUses($e); }
            return;
        }
        if ($n->kind === Node::KIND_RETURN) {
            $v = $n->value;
            if ($v !== null) { $this->collectUses($v); }
            return;
        }
        if ($n->kind === Node::KIND_CALL) {
            foreach ($n->args as $a) { $this->collectUses($a); }
            return;
        }
        if ($n->kind === Node::KIND_IF) {
            $this->collectUses($n->cond);
            $this->collectUses($n->then);
            if ($n->else !== null) { $this->collectUses($n->else); }
            return;
        }
        if ($n->kind === Node::KIND_WHILE) {
            $this->collectUses($n->cond);
            $this->collectUses($n->body);
            return;
        }
        if ($n->kind === Node::KIND_FOR) {
            if ($n->init !== null) { $this->collectUses($n->init); }
            if ($n->cond !== null) { $this->collectUses($n->cond); }
            if ($n->step !== null) { $this->collectUses($n->step); }
            $this->collectUses($n->body);
            return;
        }
        if ($n->kind === Node::KIND_DOWHILE) {
            $this->collectUses($n->body);
            $this->collectUses($n->cond);
            return;
        }
        if ($n->kind === Node::KIND_FOREACH) {
            $this->collectUses($n->array);
            $this->collectUses($n->body);
            return;
        }
        if ($n->kind === Node::KIND_SWITCH) {
            $this->collectUses($n->subject);
            foreach ($n->arms as $arm) {
                if ($arm->value !== null) { $this->collectUses($arm->value); }
                foreach ($arm->body as $s) { $this->collectUses($s); }
            }
            return;
        }
        if ($n->kind === Node::KIND_MATCH) {
            $this->collectUses($n->subject);
            foreach ($n->arms as $arm) {
                $conds = $arm->conds;
                if ($conds !== null) {
                    foreach ($conds as $c) { $this->collectUses($c); }
                }
                $this->collectUses($arm->body);
            }
            return;
        }
        if ($n->kind === Node::KIND_BLOCK) {
            foreach ($n->stmts as $s) { $this->collectUses($s); }
            return;
        }
        if ($n->kind === Node::KIND_ARRAY_LIT) {
            foreach ($n->elements as $el) {
                if ($el->key !== null) { $this->collectUses($el->key); }
                $this->collectUses($el->value);
            }
            return;
        }
        if ($n->kind === Node::KIND_ARRAY_ACCESS) {
            $this->collectUses($n->array);
            $this->collectUses($n->index);
            return;
        }
        if ($n->kind === Node::KIND_STORE_ELEMENT) {
            $this->collectUses($n->array);
            $this->collectUses($n->index);
            $this->collectUses($n->value);
            return;
        }
        if ($n->kind === Node::KIND_NEW_OBJ) {
            foreach ($n->args as $a) { $this->collectUses($a); }
            return;
        }
        if ($n->kind === Node::KIND_NEW_DYN_OBJ) {
            $this->collectUses($n->classExpr);
            foreach ($n->args as $a) { $this->collectUses($a); }
            return;
        }
        if ($n->kind === Node::KIND_PROPERTY_ACCESS) {
            $this->collectUses($n->object);
            return;
        }
        if ($n->kind === Node::KIND_STORE_PROPERTY) {
            $this->collectUses($n->object);
            $this->collectUses($n->value);
            return;
        }
        if ($n->kind === Node::KIND_DYN_PROP) {
            $this->collectUses($n->object);
            $this->collectUses($n->name);
            return;
        }
        if ($n->kind === Node::KIND_STORE_DYN_PROP) {
            $this->collectUses($n->object);
            $this->collectUses($n->name);
            $this->collectUses($n->value);
            return;
        }
        if ($n->kind === Node::KIND_METHOD_CALL) {
            $this->collectUses($n->object);
            foreach ($n->args as $a) { $this->collectUses($a); }
            return;
        }
        if ($n->kind === Node::KIND_STATIC_CALL) {
            foreach ($n->args as $a) { $this->collectUses($a); }
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
        if ($n->kind === Node::KIND_STORE_LOCAL) {
            if (!isset($this->usedLocals[$n->name]) && $this->isPure($n->value)) {
                return null;
            }
            return $n;
        }
        if ($n->kind === Node::KIND_IF) {
            $n->then = $this->rewriteBlock($n->then);
            if ($n->else !== null) {
                $n->else = $this->rewriteBlock($n->else);
            }
            return $n;
        }
        if ($n->kind === Node::KIND_WHILE) {
            $n->body = $this->rewriteBlock($n->body);
            return $n;
        }
        if ($n->kind === Node::KIND_FOR) {
            $n->body = $this->rewriteBlock($n->body);
            return $n;
        }
        if ($n->kind === Node::KIND_DOWHILE) {
            $n->body = $this->rewriteBlock($n->body);
            return $n;
        }
        if ($n->kind === Node::KIND_BLOCK) {
            return $this->rewriteBlock($n);
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
        if ($n->kind === Node::KIND_INT_CONST
            || $n->kind === Node::KIND_FLOAT_CONST
            || $n->kind === Node::KIND_STRING_CONST
            || $n->kind === Node::KIND_BOOL_CONST
            || $n->kind === Node::KIND_NULL_CONST
            || $n->kind === Node::KIND_LOAD_LOCAL) {
            return true;
        }
        if ($n->kind === Node::KIND_ADD) { return $this->isPure($n->left) && $this->isPure($n->right); }
        if ($n->kind === Node::KIND_SUB) { return $this->isPure($n->left) && $this->isPure($n->right); }
        if ($n->kind === Node::KIND_MUL) { return $this->isPure($n->left) && $this->isPure($n->right); }
        if ($n->kind === Node::KIND_DIV) { return $this->isPure($n->left) && $this->isPure($n->right); }
        if ($n->kind === Node::KIND_MOD) { return $this->isPure($n->left) && $this->isPure($n->right); }
        if ($n->kind === Node::KIND_NEG) { return $this->isPure($n->operand); }
        if ($n->kind === Node::KIND_NOT) { return $this->isPure($n->operand); }
        if ($n->kind === Node::KIND_BITOP) { return $this->isPure($n->left) && $this->isPure($n->right); }
        if ($n->kind === Node::KIND_BITNOT) { return $this->isPure($n->operand); }
        if ($n->kind === Node::KIND_CONCAT) { return $this->isPure($n->left) && $this->isPure($n->right); }
        if ($n->kind === Node::KIND_CMP) { return $this->isPure($n->left) && $this->isPure($n->right); }
        return false;
    }
}
