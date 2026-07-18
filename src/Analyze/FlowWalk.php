<?php

namespace Analyze;

use Parser\Ast\BinaryOp;
use Parser\Ast\Block;
use Parser\Ast\CallExpr;
use Parser\Ast\DoWhileStmt;
use Parser\Ast\EchoStmt;
use Parser\Ast\Expr;
use Parser\Ast\ExpressionStmt;
use Parser\Ast\ForStmt;
use Parser\Ast\ForeachStmt;
use Parser\Ast\IfStmt;
use Parser\Ast\InstanceofExpr;
use Parser\Ast\NullLiteral;
use Parser\Ast\ReturnStmt;
use Parser\Ast\Stmt;
use Parser\Ast\SwitchStmt;
use Parser\Ast\ThrowStmt;
use Parser\Ast\TryCatchStmt;
use Parser\Ast\UnaryOp;
use Parser\Ast\Variable;
use Parser\Ast\WhileStmt;

/**
 * Flow-SENSITIVE traversal of a {@see Unit}: pairs every expression with the
 * variable scope in effect where it appears, applying type NARROWING from the
 * guards that dominate it:
 *
 *   - `if ($x instanceof Foo) { … $x is Foo … }`
 *   - `if ($x !== null) { … $x is non-null … }` / the `=== null` else-branch
 *   - `if (is_int($x)) { … $x is int … }` (is_string/is_float/is_bool/is_array/is_object)
 *   - the type-guard idiom `if (!($x instanceof Foo)) { return; } … $x is Foo …`
 *   - the same narrowings on a loop condition (true at each body entry)
 *   - `&&` chains and a leading `!` compose
 *
 * Narrowing is SOUND even for a variable the flow-insensitive {@see Scope} gave
 * up on (marked unknown): the runtime guard guarantees the type at that point.
 * Consumers read `exprs[i]` under `scopes[i]`.
 */
final class FlowWalk
{
    /** @var Expr[] */
    public array $exprs = [];
    /** @var Scope[] parallel to $exprs */
    public array $scopes = [];

    public function __construct(private Index $idx) {}

    public function walkUnit(Unit $u): void
    {
        $base = Scope::build($u, $this->idx);
        if ($u->exprBody !== null) {
            $this->emit($u->exprBody, $base);
            return;
        }
        $this->walkStmts($u->stmts, $base);
    }

    /** Flatten one expression subtree, pairing each node with $scope. */
    private function emit(?Expr $e, Scope $scope): void
    {
        if ($e === null) { return; }
        $w = new AstWalk(true);
        $w->exprTree($e);
        foreach ($w->exprs as $sub) {
            $this->exprs[] = $sub;
            $this->scopes[] = $scope;
        }
    }

    /** @param Stmt[] $stmts */
    private function walkStmts(array $stmts, Scope $scope): void
    {
        $cur = $scope;
        foreach ($stmts as $s) {
            $cur = $this->walkStmt($s, $cur);
        }
    }

    /** Returns the scope for statements AFTER $s (type-guard refinement). */
    private function walkStmt(Stmt $s, Scope $scope): Scope
    {
        if ($s instanceof ExpressionStmt) { $this->emit($s->expr, $scope); return $scope; }
        if ($s instanceof EchoStmt) { foreach ($s->exprs as $e) { $this->emit($e, $scope); } return $scope; }
        if ($s instanceof ReturnStmt) { $this->emit($s->value, $scope); return $scope; }
        if ($s instanceof ThrowStmt) { $this->emit($s->expr, $scope); return $scope; }
        if ($s instanceof IfStmt) { return $this->walkIf($s, $scope); }
        if ($s instanceof WhileStmt) {
            $this->emit($s->condition, $scope);
            $this->walkStmts($s->body->statements, $this->apply($scope, $this->narrow($s->condition, true, $scope)));
            return $scope;
        }
        if ($s instanceof DoWhileStmt) {
            $this->walkStmts($s->body->statements, $scope);
            $this->emit($s->condition, $scope);
            return $scope;
        }
        if ($s instanceof ForStmt) {
            foreach ($s->init as $e) { $this->emit($e, $scope); }
            $this->emit($s->condition, $scope);
            foreach ($s->update as $e) { $this->emit($e, $scope); }
            $this->walkStmts($s->body->statements, $scope);
            return $scope;
        }
        if ($s instanceof ForeachStmt) {
            $this->emit($s->expr, $scope);
            $this->walkStmts($s->body->statements, $scope);
            return $scope;
        }
        if ($s instanceof SwitchStmt) {
            $this->emit($s->expr, $scope);
            foreach ($s->cases as $arm) {
                $this->emit($arm->value, $scope);
                $this->walkStmts($arm->body, $scope);
            }
            return $scope;
        }
        if ($s instanceof TryCatchStmt) {
            $this->walkStmts($s->try->statements, $scope);
            foreach ($s->catches as $c) { $this->walkStmts($c->body->statements, $scope); }
            if ($s->finally !== null) { $this->walkStmts($s->finally->statements, $scope); }
            return $scope;
        }
        return $scope;
    }

    private function walkIf(IfStmt $s, Scope $scope): Scope
    {
        $this->emit($s->condition, $scope);
        $thenScope = $this->apply($scope, $this->narrow($s->condition, true, $scope));
        $this->walkStmts($s->then->statements, $thenScope);

        $elseScope = $this->apply($scope, $this->narrow($s->condition, false, $scope));
        foreach ($s->elseifs as $arm) {
            $this->emit($arm->condition, $elseScope);
            $this->walkStmts($arm->body->statements, $this->apply($elseScope, $this->narrow($arm->condition, true, $elseScope)));
            $elseScope = $this->apply($elseScope, $this->narrow($arm->condition, false, $elseScope));
        }
        if ($s->else !== null) {
            $this->walkStmts($s->else->statements, $elseScope);
        }

        // Type-guard: `if (COND) { return/throw; }` with no else ⇒ code after the
        // `if` only runs when COND was FALSE, so the negated narrowing holds.
        if ($s->else === null && \count($s->elseifs) === 0 && $this->alwaysExits($s->then)) {
            return $this->apply($scope, $this->narrow($s->condition, false, $scope));
        }
        return $scope;
    }

    private function alwaysExits(Block $b): bool
    {
        $n = \count($b->statements);
        if ($n === 0) { return false; }
        $last = $b->statements[$n - 1];
        return $last instanceof ReturnStmt || $last instanceof ThrowStmt;
    }

    /**
     * Refinements a condition proves when it evaluates to $positive.
     * @return array<string, Ty>
     */
    private function narrow(?Expr $cond, bool $positive, Scope $scope): array
    {
        if ($cond === null) { return []; }
        if ($cond instanceof UnaryOp && $cond->op === '!') {
            return $this->narrow($cond->operand, !$positive, $scope);
        }
        if ($cond instanceof BinaryOp && ($cond->op === '&&' || $cond->op === 'and') && $positive) {
            $l = $this->narrow($cond->left, true, $scope);
            $r = $this->narrow($cond->right, true, $scope);
            foreach ($r as $k => $v) { $l[$k] = $v; }
            return $l;
        }
        if ($cond instanceof InstanceofExpr && $cond->operand instanceof Variable) {
            $cls = \ltrim($cond->class, '\\');
            $low = \strtolower($cls);
            if ($positive && $low !== 'self' && $low !== 'static' && $low !== 'parent' && $cls !== '') {
                return [$cond->operand->name => new Ty(Ty::KIND_OBJECT, false, $cls)];
            }
            return [];
        }
        if ($cond instanceof BinaryOp) {
            $var = $this->nullCmpVar($cond);
            if ($var !== null) {
                $isEq = $cond->op === '===' || $cond->op === '==';
                // `$x === null` true ⇒ null; false ⇒ non-null. `!==` inverts.
                $wantNull = $isEq === $positive;
                return [$var->name => $wantNull ? new Ty(Ty::KIND_NULL, true) : $this->nonNull($var->name, $scope)];
            }
        }
        if ($cond instanceof CallExpr && $positive) {
            $t = $this->predicateTy($cond->function);
            if ($t !== null && \count($cond->args) === 1 && $cond->args[0] instanceof Variable) {
                return [$cond->args[0]->name => $t];
            }
        }
        // Truthy guard: `if ($x)` ⇒ $x is non-null in the then-branch. (The false
        // branch stays broad — falsy is null|0|""|false|[], not one type.)
        if ($cond instanceof Variable && $positive) {
            return [$cond->name => $this->nonNull($cond->name, $scope)];
        }
        return [];
    }

    private function nullCmpVar(BinaryOp $b): ?Variable
    {
        if ($b->op !== '===' && $b->op !== '!==' && $b->op !== '==' && $b->op !== '!=') { return null; }
        if ($b->left instanceof Variable && $b->right instanceof NullLiteral) { return $b->left; }
        if ($b->right instanceof Variable && $b->left instanceof NullLiteral) { return $b->right; }
        return null;
    }

    /** The non-null form of a variable's known type (`?Foo` → `Foo`; unknown stays unknown). */
    private function nonNull(string $var, Scope $scope): Ty
    {
        $t = $scope->typeOf($var);
        if ($t->kind === Ty::KIND_NULL || $t->kind === Ty::KIND_UNKNOWN) { return new Ty(Ty::KIND_UNKNOWN); }
        return new Ty($t->kind, false, $t->className);
    }

    private function predicateTy(string $fn): ?Ty
    {
        $n = \strtolower(\ltrim($fn, '\\'));
        if ($n === 'is_int' || $n === 'is_integer' || $n === 'is_long') { return new Ty(Ty::KIND_INT); }
        if ($n === 'is_string') { return new Ty(Ty::KIND_STRING); }
        if ($n === 'is_float' || $n === 'is_double') { return new Ty(Ty::KIND_FLOAT); }
        if ($n === 'is_bool') { return new Ty(Ty::KIND_BOOL); }
        if ($n === 'is_array') { return new Ty(Ty::KIND_ARRAY_); }
        return null;
    }

    /**
     * @param array<string, Ty> $refine
     */
    private function apply(Scope $scope, array $refine): Scope
    {
        if (\count($refine) === 0) { return $scope; }
        $s = new Scope();
        $s->vars = $scope->vars;
        foreach ($refine as $name => $ty) { $s->vars[$name] = $ty; }
        return $s;
    }
}
