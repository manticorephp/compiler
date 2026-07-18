<?php

namespace Analyze\Rules;

use Analyze\Diagnostic;
use Analyze\FlowWalk;
use Analyze\Index;
use Analyze\Infer;
use Analyze\ParsedFile;
use Analyze\Ty;
use Analyze\Units;
use Parser\Ast\BinaryOp;

/**
 * Rejects arithmetic (`+ - * / %`) on a provably-string operand. Manticore
 * follows strict_types and a step beyond: a numeric string is NOT silently
 * coerced — cast it. Concatenation (`.`) is the string operator and is fine.
 *
 * Runs per scope, so a string-typed variable / parameter is caught too, not just
 * a literal. An unknown operand is left alone.
 */
final class StringArithmetic
{
    /** @var Diagnostic[] */
    public array $diags = [];

    /** @return Diagnostic[] */
    public function run(ParsedFile $pf, Index $idx): array
    {
        $units = new Units();
        $units->collect($pf->program->statements);
        foreach ($units->units as $u) {
            $flow = new FlowWalk($idx);
            $flow->walkUnit($u);
            $count = \count($flow->exprs);
            $k = 0;
            while ($k < $count) {
                $e = $flow->exprs[$k];
                $scope = $flow->scopes[$k];
                $k = $k + 1;
                if (!($e instanceof BinaryOp)) { continue; }
                $op = $e->op;
                if ($op !== '+' && $op !== '-' && $op !== '*' && $op !== '/' && $op !== '%') { continue; }
                $infer = new Infer($idx, $scope);
                $l = $infer->of($e->left);
                $r = $infer->of($e->right);
                if ($l->kind === Ty::KIND_STRING || $r->kind === Ty::KIND_STRING) {
                    $this->diags[] = Diagnostic::error(
                        $pf->path, $e->span->line, $e->span->column, 'arith.string',
                        'arithmetic (`' . $op . '`) on a string operand — cast explicitly ((int)/(float)) to compute on it'
                    );
                }
            }
        }
        return $this->diags;
    }
}
