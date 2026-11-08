<?php

namespace Analyze\Rules;

use Analyze\AstWalk;
use Analyze\Diagnostic;
use Analyze\ParsedFile;
use Parser\Ast\BinaryOp;
use Parser\Ast\CallExpr;
use Parser\Ast\Expr;
use Parser\Ast\StringLiteral;

/**
 * `require`/`include` of a constant path whose file is not in the compile set.
 * A compiled program does not read source at run time, so such a target never
 * runs: `require` throws `Error: Failed opening required` and `include` yields
 * false. Constant = string literals joined by `.` (`__DIR__` is already folded
 * to a literal by the parser). A missing file is flagged for `require` only —
 * an optional `include` of an absent file is a deliberate php idiom.
 */
final class UnresolvedInclude
{
    /**
     * @param array<string, bool> $unitPaths realpaths of the compile set
     * @return Diagnostic[]
     */
    public function run(ParsedFile $pf, array $unitPaths): array
    {
        $diags = [];
        $walk = new AstWalk();
        $walk->stmts($pf->program->statements);
        foreach ($walk->exprs as $e) {
            if (!($e instanceof CallExpr) || $e->function !== '__mc_require_value') { continue; }
            foreach (self::constPaths($e->args[0]) as $path) {
                $required = isset($e->args[1]) && $e->args[1] instanceof \Parser\Ast\IntLiteral && $e->args[1]->value === 1;
                if (\Parser\IncludePath::isComposerGenerated($path)) { continue; }
                $real = \realpath($path);
                if ($real === false) {
                    if (!$required) { continue; }
                    $why = 'does not exist';
                } elseif (isset($unitPaths[$real])) {
                    continue;
                } else {
                    $why = 'is not part of the build';
                }
                $diags[] = Diagnostic::warning(
                    $pf->path, $e->span->line, $e->span->column, 'include.unresolved',
                    ($required ? 'require' : 'include') . " of '" . self::shown($path) . "' " . $why
                    . ($required ? ': it throws Error at run time' : ': it evaluates to false at run time')
                );
            }
        }
        return $diags;
    }

    /** Relative to the working directory when under it, so output is machine-independent. */
    private static function shown(string $p): string
    {
        $cwd = \getcwd();
        if ($cwd !== false && \str_starts_with($p, $cwd . '/')) { return \substr($p, \strlen($cwd) + 1); }
        return $p;
    }

    /**
     * The constant paths a target can name: one, or every constant arm of a ternary
     * (`require $c ? A : B` loads one of two files, and both must be in the build).
     *
     * @return string[]
     */
    private static function constPaths(Expr $e): array
    {
        if ($e instanceof \Parser\Ast\Ternary) {
            return \array_merge(self::constPaths($e->then ?? $e->condition), self::constPaths($e->else));
        }
        $p = \Parser\IncludePath::fold($e);
        return $p === null ? [] : [$p];
    }
}
