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
    /** @param array<int, Unit> $byId spl_object_id of an expression => its unit */
    private function __construct(private ?Unit $main, private array $byId) {}

    public static function build(Program $p): UnitLookup
    {
        $units = new Units();
        $units->collect($p->statements, $p->useAliases);
        $main = null;
        /** @var array<int, Unit> $byId */
        $byId = [];
        foreach ($units->units as $u) {
            if ($u->label === '{closure}') { continue; }
            if ($u->label === '{main}') { $main = $u; continue; }
            $w = new AstWalk();
            if ($u->exprBody !== null) { $w->exprTree($u->exprBody); } else { $w->stmts($u->stmts); }
            foreach ($w->exprs as $x) {
                $id = \spl_object_id($x);
                if (!isset($byId[$id])) { $byId[$id] = $u; }
            }
        }
        return new UnitLookup($main, $byId);
    }

    public function envAt(Expr $target, Index $idx, string $file): TypeEnv
    {
        $u = $this->byId[\spl_object_id($target)] ?? $this->main;
        return $u === null ? new TypeEnv() : Flow::envAtUnit($u, $idx, $file, $target);
    }
}
