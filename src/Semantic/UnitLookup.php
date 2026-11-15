<?php

namespace Semantic;

use Analyze\AstWalk;
use Analyze\Index;
use Analyze\Unit;
use Analyze\Units;
use Parser\Ast\Expr;
use Parser\Ast\Program;

/**
 * Expression -> the analysis unit Flow walks for it, built once per file version. Same choice as
 * {@see Flow::envAt}: the first function / method unit holding the expression, else `{main}`; a
 * closure is walked inside its enclosing unit.
 */
final class UnitLookup
{
    /**
     * @param array<int, Unit> $byId spl_object_id of an expression => its unit
     * @param Unit[] $spans every unit with a range, closures included
     * @param array<int, \Parser\Ast\ForeachStmt> $foreachOf spl_object_id of a foreach key / value expression => its statement
     */
    private function __construct(private ?Unit $main, private array $byId, private array $spans = [], private array $foreachOf = []) {}

    public static function build(Program $p): UnitLookup
    {
        $units = new Units();
        $units->collect($p->statements, $p->useAliases);
        $main = null;
        /** @var array<int, Unit> $byId */
        $byId = [];
        $spans = [];
        $foreachOf = [];
        foreach ($units->units as $u) {
            if ($u->span !== null) { $spans[] = $u; }
            if ($u->label === '{closure}') { continue; }
            if ($u->label === '{main}') { $main = $u; continue; }
            $w = new AstWalk();
            if ($u->exprBody !== null) { $w->exprTree($u->exprBody); } else { $w->stmts($u->stmts); }
            foreach ($w->foreachs as $fe) {
                $foreachOf[\spl_object_id($fe->value)] = $fe;
                if ($fe->key !== null) { $foreachOf[\spl_object_id($fe->key)] = $fe; }
            }
            foreach ($w->exprs as $x) {
                $id = \spl_object_id($x);
                if (!isset($byId[$id])) { $byId[$id] = $u; }
            }
        }
        return new UnitLookup($main, $byId, $spans, $foreachOf);
    }

    /** The foreach statement whose key or value is this expression. */
    public function foreachOf(Expr $e): ?\Parser\Ast\ForeachStmt
    {
        return $this->foreachOf[\spl_object_id($e)] ?? null;
    }

    /** The innermost unit one of whose parameters `$name` is declared at the point. */
    public function paramUnit(string $name, int $line, int $col): ?Unit
    {
        $p = $line * 4294967296 + $col;
        $best = null;
        $bestStart = -1;
        foreach ($this->spans as $u) {
            $sp = $u->span;
            $start = $sp->startLine() * 4294967296 + $sp->startColumn();
            foreach ($u->params as $q) {
                if ($q->name !== $name) { continue; }
                $ps = $q->span;
                $pend = $ps->endLine() === 0 ? PHP_INT_MAX : $ps->endLine() * 4294967296 + $ps->endColumn();
                if ($p >= $ps->startLine() * 4294967296 + $ps->startColumn() && $p <= $pend && $start > $bestStart) { $best = $u; $bestStart = $start; }
            }
        }
        return $best;
    }

    public function envAt(Expr $target, Index $idx, string $file): TypeEnv
    {
        $u = $this->byId[\spl_object_id($target)] ?? $this->main;
        return $u === null ? new TypeEnv() : Flow::envAtUnit($u, $idx, $file, $target);
    }
}
