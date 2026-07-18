<?php

namespace Analyze;

use Parser\Ast\Block;
use Parser\Ast\Expr;
use Parser\Ast\Stmt;
use Parser\Ast\Param;
// Stmt variants
use Parser\Ast\ExpressionStmt;
use Parser\Ast\EchoStmt;
use Parser\Ast\ReturnStmt;
use Parser\Ast\IfStmt;
use Parser\Ast\WhileStmt;
use Parser\Ast\DoWhileStmt;
use Parser\Ast\ForStmt;
use Parser\Ast\ForeachStmt;
use Parser\Ast\FunctionStmt;
use Parser\Ast\NamespaceStmt;
use Parser\Ast\ClassStmt;
use Parser\Ast\ThrowStmt;
use Parser\Ast\TryCatchStmt;
use Parser\Ast\SwitchStmt;
use Parser\Ast\StaticLocalStmt;
// Expr variants with children
use Parser\Ast\BinaryOp;
use Parser\Ast\UnaryOp;
use Parser\Ast\Ternary;
use Parser\Ast\NullCoalesce;
use Parser\Ast\Cast;
use Parser\Ast\InstanceofExpr;
use Parser\Ast\Assign;
use Parser\Ast\CompoundAssign;
use Parser\Ast\RefAssign;
use Parser\Ast\IncDec;
use Parser\Ast\ArrayLit;
use Parser\Ast\ArrayAccess;
use Parser\Ast\CallExpr;
use Parser\Ast\MethodCallExpr;
use Parser\Ast\PropertyAccess;
use Parser\Ast\DynProp;
use Parser\Ast\StaticCall;
use Parser\Ast\NewExpr;
use Parser\Ast\NewDynExpr;
use Parser\Ast\Invoke;
use Parser\Ast\CloneExpr;
use Parser\Ast\ArrowFn;
use Parser\Ast\Closure;
use Parser\Ast\MatchExpr;
use Parser\Ast\NamedArg;
use Parser\Ast\Spread;
use Parser\Ast\YieldExpr;
use Parser\Ast\DynamicStaticAccess;
use Parser\Ast\DynamicStaticCall;

/**
 * Flattens a statement tree into every expression node it contains, descending
 * through control flow, declaration bodies (functions, methods, closures) and
 * default-value expressions. The reusable AST spine the rules walk over.
 *
 * Collect into an instance property rather than a by-ref accumulator — a
 * mutating method call is the reliable path under the self-host build, where a
 * by-ref array parameter can lose its element type.
 */
final class AstWalk
{
    /** @var Expr[] every expression node, in pre-order */
    public array $exprs = [];

    /**
     * When true, a nested `Closure` / `ArrowFn` is recorded but NOT descended
     * into — its body belongs to a different variable scope and is analyzed as
     * its own unit. Leave false to flatten everything (e.g. a yield scan).
     */
    public function __construct(private bool $stopAtClosures = false) {}

    /** @param Stmt[] $stmts */
    public function stmts(array $stmts): void
    {
        foreach ($stmts as $s) { $this->stmt($s); }
    }

    /** Walk a single expression subtree (e.g. an arrow-fn body). */
    public function exprTree(Expr $e): void
    {
        $this->expr($e);
    }

    private function block(?Block $b): void
    {
        if ($b !== null) { $this->stmts($b->statements); }
    }

    /** @param Param[] $params  descend into default-value expressions */
    private function params(array $params): void
    {
        foreach ($params as $p) { $this->expr($p->default); }
    }

    private function stmt(Stmt $s): void
    {
        if ($s instanceof ExpressionStmt) { $this->expr($s->expr); return; }
        if ($s instanceof EchoStmt) { foreach ($s->exprs as $e) { $this->expr($e); } return; }
        if ($s instanceof ReturnStmt) { $this->expr($s->value); return; }
        if ($s instanceof IfStmt) {
            $this->expr($s->condition);
            $this->block($s->then);
            foreach ($s->elseifs as $arm) { $this->expr($arm->condition); $this->block($arm->body); }
            $this->block($s->else);
            return;
        }
        if ($s instanceof WhileStmt) { $this->expr($s->condition); $this->block($s->body); return; }
        if ($s instanceof DoWhileStmt) { $this->block($s->body); $this->expr($s->condition); return; }
        if ($s instanceof ForStmt) {
            foreach ($s->init as $e) { $this->expr($e); }
            $this->expr($s->condition);
            foreach ($s->update as $e) { $this->expr($e); }
            $this->block($s->body);
            return;
        }
        if ($s instanceof ForeachStmt) {
            $this->expr($s->expr);
            $this->expr($s->key);
            $this->expr($s->value);
            $this->block($s->body);
            return;
        }
        if ($s instanceof FunctionStmt) {
            // A nested function declaration is its own scope — skip its body when
            // collecting a single unit's expressions.
            if ($this->stopAtClosures) { return; }
            $this->params($s->decl->params);
            $this->block($s->decl->body);
            return;
        }
        if ($s instanceof NamespaceStmt) { $this->block($s->body); return; }
        if ($s instanceof ClassStmt) {
            if ($this->stopAtClosures) { return; }
            foreach ($s->decl->properties as $prop) { $this->expr($prop->default); }
            foreach ($s->decl->consts as $c) { $this->expr($c->value); }
            foreach ($s->decl->cases as $case) { $this->expr($case->value); }
            foreach ($s->decl->methods as $m) {
                $this->params($m->params);
                $this->block($m->body);
            }
            return;
        }
        if ($s instanceof ThrowStmt) { $this->expr($s->expr); return; }
        if ($s instanceof TryCatchStmt) {
            $this->block($s->try);
            foreach ($s->catches as $c) { $this->block($c->body); }
            $this->block($s->finally);
            return;
        }
        if ($s instanceof SwitchStmt) {
            $this->expr($s->expr);
            foreach ($s->cases as $arm) {
                $this->expr($arm->value);
                $this->stmts($arm->body);
            }
            return;
        }
        if ($s instanceof StaticLocalStmt) {
            foreach ($s->decls as $d) { $this->expr($d->default); }
            return;
        }
        // Break / Continue / Global / Goto / Label / UseDecl carry no expressions.
    }

    private function expr(?Expr $e): void
    {
        if ($e === null) { return; }
        $this->exprs[] = $e;

        if ($e instanceof BinaryOp) { $this->expr($e->left); $this->expr($e->right); return; }
        if ($e instanceof UnaryOp) { $this->expr($e->operand); return; }
        if ($e instanceof Ternary) { $this->expr($e->condition); $this->expr($e->then); $this->expr($e->else); return; }
        if ($e instanceof NullCoalesce) { $this->expr($e->left); $this->expr($e->right); return; }
        if ($e instanceof Cast) { $this->expr($e->operand); return; }
        if ($e instanceof InstanceofExpr) { $this->expr($e->operand); return; }
        if ($e instanceof Assign) { $this->expr($e->target); $this->expr($e->value); return; }
        if ($e instanceof CompoundAssign) { $this->expr($e->target); $this->expr($e->value); return; }
        if ($e instanceof RefAssign) { $this->expr($e->target); $this->expr($e->source); return; }
        if ($e instanceof IncDec) { $this->expr($e->operand); return; }
        if ($e instanceof ArrayLit) {
            foreach ($e->elements as $el) { $this->expr($el->key); $this->expr($el->value); }
            return;
        }
        if ($e instanceof ArrayAccess) { $this->expr($e->array); $this->expr($e->index); return; }
        if ($e instanceof CallExpr) { foreach ($e->args as $a) { $this->expr($a); } return; }
        if ($e instanceof MethodCallExpr) {
            $this->expr($e->object);
            foreach ($e->args as $a) { $this->expr($a); }
            return;
        }
        if ($e instanceof PropertyAccess) { $this->expr($e->object); return; }
        if ($e instanceof DynProp) { $this->expr($e->object); $this->expr($e->name); return; }
        if ($e instanceof StaticCall) { foreach ($e->args as $a) { $this->expr($a); } return; }
        if ($e instanceof NewExpr) { foreach ($e->args as $a) { $this->expr($a); } return; }
        if ($e instanceof NewDynExpr) { $this->expr($e->classExpr); foreach ($e->args as $a) { $this->expr($a); } return; }
        if ($e instanceof Invoke) { $this->expr($e->callee); foreach ($e->args as $a) { $this->expr($a); } return; }
        if ($e instanceof CloneExpr) { $this->expr($e->object); $this->expr($e->withProps); return; }
        if ($e instanceof ArrowFn) {
            if ($this->stopAtClosures) { return; }
            $this->params($e->params); $this->expr($e->body); return;
        }
        if ($e instanceof Closure) {
            if ($this->stopAtClosures) { return; }
            $this->params($e->params); $this->block($e->body); return;
        }
        if ($e instanceof MatchExpr) {
            $this->expr($e->subject);
            foreach ($e->arms as $arm) {
                if ($arm->conds !== null) { foreach ($arm->conds as $c) { $this->expr($c); } }
                $this->expr($arm->body);
            }
            return;
        }
        if ($e instanceof NamedArg) { $this->expr($e->value); return; }
        if ($e instanceof Spread) { $this->expr($e->value); return; }
        if ($e instanceof YieldExpr) { $this->expr($e->key); $this->expr($e->value); return; }
        if ($e instanceof DynamicStaticAccess) { $this->expr($e->receiver); return; }
        if ($e instanceof DynamicStaticCall) { $this->expr($e->receiver); foreach ($e->args as $a) { $this->expr($a); } return; }
        // Leaf: IntLiteral / FloatLiteral / StringLiteral / BoolLiteral /
        // NullLiteral / Variable / Identifier / MagicConstant / StaticAccess /
        // Ellipsis — no child expressions.
    }
}
