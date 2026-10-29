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
     */
    private function __construct(private ?Unit $main, private array $byId, private array $spans = []) {}

    public static function build(Program $p): UnitLookup
    {
        $units = new Units();
        $units->collect($p->statements, $p->useAliases);
        $main = null;
        /** @var array<int, Unit> $byId */
        $byId = [];
        $spans = [];
        foreach ($units->units as $u) {
            if ($u->span !== null) { $spans[] = $u; }
            if ($u->label === '{closure}') { continue; }
            if ($u->label === '{main}') { $main = $u; continue; }
            $w = new AstWalk();
            if ($u->exprBody !== null) { $w->exprTree($u->exprBody); } else { $w->stmts($u->stmts); }
            foreach ($w->exprs as $x) {
                $id = \spl_object_id($x);
                if (!isset($byId[$id])) { $byId[$id] = $u; }
            }
        }
        return new UnitLookup($main, $byId, $spans);
    }

    /** The innermost function, method or closure holding the point that has a parameter `$name`. */
    public function paramUnit(string $name, int $line, int $col): ?Unit
    {
        $p = $line * 4294967296 + $col;
        $best = null;
        $bestStart = -1;
        foreach ($this->spans as $u) {
            $sp = $u->span;
            $has = false;
            foreach ($u->params as $q) { if ($q->name === $name) { $has = true; } }
            if (!$has) { continue; }
            $start = $sp->startLine() * 4294967296 + $sp->startColumn();
            $end = $sp->endLine() === 0 ? PHP_INT_MAX : $sp->endLine() * 4294967296 + $sp->endColumn();
            if ($p >= $start && $p <= $end && $start > $bestStart) { $best = $u; $bestStart = $start; }
        }
        return $best;
    }

    public function envAt(Expr $target, Index $idx, string $file): TypeEnv
    {
        $u = $this->byId[\spl_object_id($target)] ?? $this->main;
        return $u === null ? new TypeEnv() : Flow::envAtUnit($u, $idx, $file, $target);
    }
}
