<?php

namespace Analyze\Rules;

use Analyze\AstWalk;
use Analyze\Diagnostic;
use Analyze\ParsedFile;
use Analyze\Units;
use Parser\Ast\Assign;
use Parser\Ast\Block;
use Parser\Ast\CompoundAssign;
use Parser\Ast\DoWhileStmt;
use Parser\Ast\ForStmt;
use Parser\Ast\ForeachStmt;
use Parser\Ast\IfStmt;
use Parser\Ast\IncDec;
use Parser\Ast\RefAssign;
use Parser\Ast\StaticLocalStmt;
use Parser\Ast\Stmt;
use Parser\Ast\SwitchStmt;
use Parser\Ast\TryCatchStmt;
use Parser\Ast\Variable;
use Parser\Ast\WhileStmt;

/**
 * A MUTATED `static` local in an async program is shared by every fiber.
 *
 * A static local is a module global with a scoped name: one slot for the whole
 * process, not one per call and certainly not one per task. Under a scheduler
 * that is a silent correctness bug — two tasks in the same function interleave at
 * a suspend point and clobber each other's value, with no crash and no warning.
 * (It is also the rule `prelude/async.php` itself is written under, where it was
 * documented and enforced by nothing.)
 *
 * Only a static that is ASSIGNED somewhere in its unit is reported: a
 * `static $table = [...]` that is only ever read is a constant, and flagging it
 * would be noise. The fix is to hold the state on the task (a local), on the
 * scope (`Context::withValue`), or in an object the tasks share deliberately.
 */
final class AsyncStaticLocal
{
    /** @var Diagnostic[] */
    public array $diags = [];

    /** @return Diagnostic[] */
    public function run(ParsedFile $pf): array
    {
        if (!AsyncUse::inFile($pf)) { return []; }

        $units = new Units();
        $units->collect($pf->program->statements);
        foreach ($units->units as $u) {
            // {main} is the top-level scope: a `static` there is a plain global
            // and has nothing to do with fibers.
            if ($u->label === '{main}') { continue; }
            $statics = [];
            $this->collectStatics($u->stmts, $statics);
            if (\count($statics) === 0) { continue; }

            $walk = new AstWalk(false);
            $walk->stmts($u->stmts);
            foreach ($statics as $name => $line) {
                if (!$this->isAssigned($walk, $name)) { continue; }
                $this->diags[] = Diagnostic::warning(
                    $pf->path, $line, 1, 'async.static-local',
                    'mutable `static $' . $name . '` in ' . $u->label . ' is shared by every fiber'
                    . ' — under a scheduler two tasks in this function clobber each other;'
                    . ' hold it on the task, or in Async\\Context::withValue()'
                );
            }
        }
        return $this->diags;
    }

    /**
     * `static $x` declarations in this unit's own statements, name → line.
     * Nested declaration bodies are separate units and are visited on their own.
     *
     * @param Stmt[] $stmts
     * @param array<string,int> $out
     */
    private function collectStatics(array $stmts, array &$out): void
    {
        foreach ($stmts as $s) {
            if ($s instanceof StaticLocalStmt) {
                $line = $this->staticLine($s);
                foreach ($this->staticDecls($s) as $d) { $out[$d->name] = $line; }
                continue;
            }
            foreach ($this->childBlocks($s) as $b) {
                $this->collectStatics($b, $out);
            }
        }
    }

    /**
     * Statement lists nested directly inside $s (if / loop / try / switch bodies),
     * mirroring {@see AstWalk::stmt()} — a declaration body is NOT descended into,
     * because it is its own unit and gets its own pass.
     *
     * @return array<int,Stmt[]>
     */
    private function childBlocks(Stmt $s): array
    {
        $out = [];
        if ($s instanceof IfStmt) {
            $this->pushBlock($out, $s->then);
            foreach ($s->elseifs as $arm) { $this->pushBlock($out, $arm->body); }
            $this->pushBlock($out, $s->else);
            return $out;
        }
        if ($s instanceof WhileStmt)   { $this->pushBlock($out, $s->body); return $out; }
        if ($s instanceof DoWhileStmt) { $this->pushBlock($out, $s->body); return $out; }
        if ($s instanceof ForStmt)     { $this->pushBlock($out, $s->body); return $out; }
        if ($s instanceof ForeachStmt) { $this->pushBlock($out, $s->body); return $out; }
        if ($s instanceof TryCatchStmt) {
            $this->pushBlock($out, $s->try);
            foreach ($s->catches as $c) { $this->pushBlock($out, $c->body); }
            $this->pushBlock($out, $s->finally);
            return $out;
        }
        if ($s instanceof SwitchStmt) {
            foreach ($s->cases as $arm) { $out[] = $arm->body; }
            return $out;
        }
        return $out;
    }

    /** @param array<int,Stmt[]> $out */
    private function pushBlock(array &$out, ?Block $b): void
    {
        if ($b !== null) { $out[] = $b->statements; }
    }

    /** Field reads funnelled through a typed param — a subclass field read off a
     * base-typed expression picks the wrong layout under self-host. */
    private function staticDecls(StaticLocalStmt $s): array
    {
        return $s->decls;
    }

    private function staticLine(StaticLocalStmt $s): int
    {
        return $s->span->line;
    }

    /** Is $name written to anywhere in this unit? */
    private function isAssigned(AstWalk $walk, string $name): bool
    {
        foreach ($walk->exprs as $e) {
            if ($e instanceof Assign || $e instanceof CompoundAssign || $e instanceof RefAssign) {
                if ($this->isVar($e->target, $name)) { return true; }
            } elseif ($e instanceof IncDec) {
                if ($this->isVar($e->operand, $name)) { return true; }
            }
        }
        return false;
    }

    private function isVar(mixed $expr, string $name): bool
    {
        return $expr instanceof Variable && $expr->name === $name;
    }
}
