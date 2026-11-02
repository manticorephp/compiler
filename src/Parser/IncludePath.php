<?php

namespace Parser;

use Parser\Ast\BinaryOp;
use Parser\Ast\CallExpr;
use Parser\Ast\Expr;
use Parser\Ast\IntLiteral;
use Parser\Ast\StringLiteral;

/**
 * The one owner of "what file does this `require`/`include` name at compile
 * time". The parser folds the operand with it, so the build's file set, the
 * lowering and the analyzer all see the same literal path. `__FILE__`/`__DIR__`
 * are already literals by the time they get here.
 */
final class IncludePath
{
    /** A constant path: literals, `.` concatenation, `dirname(<const>[, <int levels>])`. */
    public static function fold(Expr $e): ?string
    {
        if ($e instanceof StringLiteral) { return $e->value; }
        if ($e instanceof BinaryOp && $e->op === '.') {
            $l = self::fold($e->left);
            $r = $l === null ? null : self::fold($e->right);
            return $r === null ? null : $l . $r;
        }
        if ($e instanceof CallExpr && \strtolower(\ltrim($e->function, '\\')) === 'dirname') {
            $n = \count($e->args);
            if ($n < 1 || $n > 2) { return null; }
            $p = self::fold($e->args[0]);
            if ($p === null) { return null; }
            $levels = 1;
            if ($n === 2) {
                $lv = $e->args[1];
                if (!($lv instanceof IntLiteral) || $lv->value < 1) { return null; }
                $levels = $lv->value;
            }
            return \dirname($p, $levels);
        }
        return null;
    }

    /**
     * composer's own generated files. A `composer: true` build compiles the
     * autoload roots and packages in and registers the loader at start-up, so
     * requiring these is a no-op that answers true.
     */
    public static function isComposerGenerated(string $path): bool
    {
        return \preg_match('~/vendor/(autoload\.php|composer/(autoload_[a-z_]+|installed)\.php)$~', $path) === 1;
    }
}
