<?php

namespace Analyze\Rules;

use Analyze\AstWalk;
use Analyze\Diagnostic;
use Analyze\Index;
use Analyze\Infer;
use Analyze\ParsedFile;
use Analyze\Scope;
use Analyze\Ty;
use Analyze\Unit;
use Analyze\Units;
use Parser\Ast\DoWhileStmt;
use Parser\Ast\ForStmt;
use Parser\Ast\ForeachStmt;
use Parser\Ast\IfStmt;
use Parser\Ast\ReturnStmt;
use Parser\Ast\Stmt;
use Parser\Ast\SwitchStmt;
use Parser\Ast\TryCatchStmt;
use Parser\Ast\WhileStmt;
use Parser\Ast\YieldExpr;

/**
 * Flags a `return <expr>` (or an arrow-fn body) whose PROVABLE type is
 * incompatible with the declared return type under strict_types. `void` is a
 * real declared type — a `return <value>` in a void function is an error.
 * Generators (any `yield`), `never`/unhinted/union returns, and abstract methods
 * are skipped.
 */
final class ReturnType
{
    /** @var Diagnostic[] */
    public array $diags = [];

    /** @var ReturnStmt[] accumulator for one unit body */
    private array $returns = [];

    /** @return Diagnostic[] */
    public function run(ParsedFile $pf, Index $idx): array
    {
        $units = new Units();
        $units->collect($pf->program->statements);
        foreach ($units->units as $u) {
            if ($u->returnType === null) { continue; }
            $target = Ty::fromHint($u->returnType);
            $scope = Scope::build($u, $idx);
            $infer = new Infer($idx, $scope);

            // Arrow function: the body expression is the implicit return.
            if ($u->exprBody !== null) {
                if ($this->permissive($target) || $target->kind === Ty::KIND_VOID) { continue; }
                $src = $infer->of($u->exprBody);
                if (!Ty::assignable($target, $src, $idx)) {
                    $this->diags[] = Diagnostic::error(
                        $pf->path, $u->exprBody->span->line, $u->exprBody->span->column, 'return.type',
                        $u->label . ': returns ' . $src->display() . ', declared ' . $target->display()
                    );
                }
                continue;
            }

            if ($this->hasYield($u->stmts)) { continue; }

            $this->returns = [];
            $this->collectReturns($u->stmts);

            if ($target->kind === Ty::KIND_VOID) {
                foreach ($this->returns as $ret) {
                    if ($ret->value === null) { continue; }
                    $this->diags[] = Diagnostic::error(
                        $pf->path, $ret->span->line, $ret->span->column, 'return.void',
                        $u->label . ': declared void but returns a value'
                    );
                }
                continue;
            }
            if ($this->permissive($target)) { continue; }

            foreach ($this->returns as $ret) {
                if ($ret->value === null) { continue; }
                $src = $infer->of($ret->value);
                if (Ty::assignable($target, $src, $idx)) { continue; }
                $this->diags[] = Diagnostic::error(
                    $pf->path, $ret->span->line, $ret->span->column, 'return.type',
                    $u->label . ': returns ' . $src->display() . ', declared ' . $target->display()
                );
            }
        }
        return $this->diags;
    }

    private function permissive(Ty $t): bool
    {
        return $t->kind === Ty::KIND_UNKNOWN || $t->kind === Ty::KIND_MIXED
            || $t->kind === Ty::KIND_OPAQUE || $t->kind === Ty::KIND_NEVER;
    }

    /** @param Stmt[] $stmts */
    private function hasYield(array $stmts): bool
    {
        $walk = new AstWalk();
        $walk->stmts($stmts);
        foreach ($walk->exprs as $e) {
            if ($e instanceof YieldExpr) { return true; }
        }
        return false;
    }

    /**
     * Collect `return` statements WITHOUT descending into nested closures (they
     * live in expressions this statement-only walk never enters).
     *
     * @param Stmt[] $stmts
     */
    private function collectReturns(array $stmts): void
    {
        foreach ($stmts as $s) {
            if ($s instanceof ReturnStmt) { $this->returns[] = $s; continue; }
            if ($s instanceof IfStmt) {
                $this->collectReturns($s->then->statements);
                foreach ($s->elseifs as $arm) { $this->collectReturns($arm->body->statements); }
                if ($s->else !== null) { $this->collectReturns($s->else->statements); }
                continue;
            }
            if ($s instanceof WhileStmt) { $this->collectReturns($s->body->statements); continue; }
            if ($s instanceof DoWhileStmt) { $this->collectReturns($s->body->statements); continue; }
            if ($s instanceof ForStmt) { $this->collectReturns($s->body->statements); continue; }
            if ($s instanceof ForeachStmt) { $this->collectReturns($s->body->statements); continue; }
            if ($s instanceof TryCatchStmt) {
                $this->collectReturns($s->try->statements);
                foreach ($s->catches as $c) { $this->collectReturns($c->body->statements); }
                if ($s->finally !== null) { $this->collectReturns($s->finally->statements); }
                continue;
            }
            if ($s instanceof SwitchStmt) {
                foreach ($s->cases as $arm) { $this->collectReturns($arm->body); }
                continue;
            }
        }
    }
}
