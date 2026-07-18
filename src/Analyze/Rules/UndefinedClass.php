<?php

namespace Analyze\Rules;

use Analyze\AstWalk;
use Analyze\Builtins;
use Analyze\Diagnostic;
use Analyze\Index;
use Analyze\ParsedFile;
use Parser\Ast\ClassStmt;
use Parser\Ast\DoWhileStmt;
use Parser\Ast\ForStmt;
use Parser\Ast\ForeachStmt;
use Parser\Ast\IfStmt;
use Parser\Ast\InstanceofExpr;
use Parser\Ast\NamespaceStmt;
use Parser\Ast\NewExpr;
use Parser\Ast\StaticAccess;
use Parser\Ast\StaticCall;
use Parser\Ast\Stmt;
use Parser\Ast\SwitchStmt;
use Parser\Ast\TryCatchStmt;
use Parser\Ast\WhileStmt;

/**
 * Flags a reference to a class/interface/trait that is defined nowhere the
 * analyzer can see — a typo or a missing import. Checked references: `new`,
 * `instanceof`, `Foo::method()` / `Foo::CONST` / `Foo::class`, `extends`,
 * `implements`, trait `use`, and `catch` types.
 *
 * CLOSED-WORLD: the check only runs in whole-project mode (a directory or
 * multiple files) so a cross-file class in a single-file run is never
 * mis-flagged. The symbol universe is the analyzed files + the parsed prelude
 * (Throwable/Exception/Error hierarchy, Resource, Reflection) + {@see Builtins}
 * (PHP core classes/interfaces). `self`/`static`/`parent` are always allowed.
 */
final class UndefinedClass
{
    /** @var Diagnostic[] */
    public array $diags = [];

    private ParsedFile $pf;
    private Index $idx;

    /** @return Diagnostic[] */
    public function run(ParsedFile $pf, Index $idx): array
    {
        $this->pf = $pf;
        $this->idx = $idx;

        // Expression-level references (precise spans).
        $walk = new AstWalk();
        $walk->stmts($pf->program->statements);
        foreach ($walk->exprs as $e) {
            if ($e instanceof NewExpr) {
                $this->check($e->class, $e->span->line, $e->span->column);
            } elseif ($e instanceof InstanceofExpr) {
                $this->check($e->class, $e->span->line, $e->span->column);
            } elseif ($e instanceof StaticCall) {
                $this->check($e->class, $e->span->line, $e->span->column);
            } elseif ($e instanceof StaticAccess) {
                $this->check($e->class, $e->span->line, $e->span->column);
            }
        }

        // Declaration-level references: extends / implements / use, and catch.
        $this->scanStmts($pf->program->statements);

        return $this->diags;
    }

    /** @param Stmt[] $stmts */
    private function scanStmts(array $stmts): void
    {
        foreach ($stmts as $s) {
            if ($s instanceof ClassStmt) {
                $line = $s->decl->span->line;
                $col = $s->decl->span->column;
                foreach ($s->decl->extends as $c) { $this->check($c, $line, $col); }
                foreach ($s->decl->implements as $c) { $this->check($c, $line, $col); }
                foreach ($s->decl->uses as $c) { $this->check($c, $line, $col); }
                // Method bodies are covered by the expression walk above.
                continue;
            }
            if ($s instanceof TryCatchStmt) {
                $this->scanStmts($s->try->statements);
                foreach ($s->catches as $cat) {
                    foreach ($cat->types as $t) { $this->check($t, $s->span->line, $s->span->column); }
                    $this->scanStmts($cat->body->statements);
                }
                if ($s->finally !== null) { $this->scanStmts($s->finally->statements); }
                continue;
            }
            if ($s instanceof NamespaceStmt && $s->body !== null) { $this->scanStmts($s->body->statements); continue; }
            if ($s instanceof IfStmt) {
                $this->scanStmts($s->then->statements);
                foreach ($s->elseifs as $arm) { $this->scanStmts($arm->body->statements); }
                if ($s->else !== null) { $this->scanStmts($s->else->statements); }
                continue;
            }
            if ($s instanceof WhileStmt) { $this->scanStmts($s->body->statements); continue; }
            if ($s instanceof DoWhileStmt) { $this->scanStmts($s->body->statements); continue; }
            if ($s instanceof ForStmt) { $this->scanStmts($s->body->statements); continue; }
            if ($s instanceof ForeachStmt) { $this->scanStmts($s->body->statements); continue; }
            if ($s instanceof SwitchStmt) {
                foreach ($s->cases as $arm) { $this->scanStmts($arm->body); }
                continue;
            }
        }
    }

    private function check(string $name, int $line, int $col): void
    {
        if ($name === '') { return; }
        $low = \strtolower(\ltrim($name, '\\'));
        if ($low === 'self' || $low === 'static' || $low === 'parent') { return; }
        if ($this->idx->findClass($name) !== null) { return; }
        if (Builtins::isKnownClass($low)) { return; }
        $this->diags[] = Diagnostic::error(
            $this->pf->path, $line, $col, 'undefined.class',
            'unknown class ' . \ltrim($name, '\\')
        );
    }
}
