<?php

namespace Analyze\Rules;

use Analyze\AstWalk;
use Analyze\ParsedFile;
use Parser\Ast\CallExpr;
use Parser\Ast\NewExpr;
use Parser\Ast\StaticCall;
use Parser\Ast\UseDeclStmt;

/**
 * Does this file use the async runtime? Both async rules gate on it, so that a
 * program that never touches `Async\` keeps its diagnostics byte-identical.
 *
 * The test is the same shape as the compiler's own demand gate — a `use` of an
 * `Async\` symbol, or a qualified call / `new` — but read off the AST rather than
 * the token stream, because a rule must not re-read the source it was handed.
 */
final class AsyncUse
{
    public static function inFile(ParsedFile $pf): bool
    {
        foreach ($pf->program->statements as $s) {
            if ($s instanceof UseDeclStmt) {
                foreach ($s->items as $it) {
                    if (self::isAsync($it->fqn)) { return true; }
                }
            }
        }
        foreach ($pf->program->useAliases as $short => $fqn) {
            if (self::isAsync($fqn)) { return true; }
        }
        $walk = new AstWalk(false);
        $walk->stmts($pf->program->statements);
        foreach ($walk->exprs as $e) {
            if ($e instanceof CallExpr && self::isAsync($e->function)) { return true; }
            if ($e instanceof StaticCall && self::isAsync($e->class)) { return true; }
            if ($e instanceof NewExpr && self::isAsync($e->class)) { return true; }
        }
        return false;
    }

    /** `Async\x`, `\Async\x` — but not a user's own `MyAsync\x`. */
    private static function isAsync(string $name): bool
    {
        $n = \ltrim($name, '\\');
        return \substr($n, 0, 6) === 'Async\\';
    }
}
