<?php

namespace Analyze\Rules;

use Analyze\AstWalk;
use Analyze\Diagnostic;
use Analyze\ParsedFile;
use Parser\Ast\CallExpr;
use Parser\Ast\StringLiteral;

/**
 * Regular-file I/O inside an async program stops the WHOLE loop.
 *
 * This is not a bug being worked around — it is a property of the platform:
 * `O_NONBLOCK` is a no-op for regular files on Linux and macOS alike, and there
 * is no thread pool / aio / io_uring here. So one `file_get_contents('/big')`
 * parks every other task for the duration (measured: 15-25 ms for a 64 MB
 * page-cache-hot read). `Async\readFile()` reads the same bytes in chunks with a
 * yield between them and keeps the worst gap at ~2 ms.
 *
 * Reported only where the call is PROVABLY on the filesystem:
 *   - a literal path with no `scheme://` (`file_get_contents('https://…')` is a
 *     network fetch, and that IS async here),
 *   - the directory walkers, which have no network form at all.
 * A computed argument is left alone — guessing would make the rule noise, and a
 * lint nobody trusts gets switched off.
 */
final class AsyncBlockingIo
{
    /** @var Diagnostic[] */
    public array $diags = [];

    /** @return Diagnostic[] */
    public function run(ParsedFile $pf): array
    {
        if (!AsyncUse::inFile($pf)) { return []; }

        $walk = new AstWalk(false);
        $walk->stmts($pf->program->statements);
        foreach ($walk->exprs as $e) {
            if (!($e instanceof CallExpr)) { continue; }
            $fn = \strtolower(\ltrim($e->function, '\\'));

            if ($fn === 'glob' || $fn === 'scandir' || $fn === 'readfile') {
                $this->report($pf, $e, $fn, 'walks the filesystem');
                continue;
            }
            if ($fn !== 'file_get_contents' && $fn !== 'file_put_contents' && $fn !== 'file') {
                continue;
            }
            if (\count($e->args) === 0) { continue; }
            $path = $e->args[0];
            if (!($path instanceof StringLiteral)) { continue; }
            $lit = $this->literalText($path);
            if (\strpos($lit, '://') !== false) { continue; }
            $verb = $fn === 'file_put_contents' ? 'writes' : 'reads';
            $this->report($pf, $e, $fn, $verb . " '" . $lit . "' on the filesystem");
        }
        return $this->diags;
    }

    /**
     * The literal's text, read through a TYPED parameter. A subclass field read
     * off a base-`Expr` value picks the wrong layout under self-host — the same
     * poly-prop trap the lowering documents — and this one handed back the string
     * POINTER as an int, which printed as a bare number in the diagnostic.
     */
    private function literalText(StringLiteral $lit): string
    {
        return $lit->value;
    }

    private function report(ParsedFile $pf, CallExpr $e, string $fn, string $what): void
    {
        $hint = $fn === 'file_get_contents' ? ' — Async\\readFile() chunks and yields'
              : ($fn === 'file_put_contents' ? ' — Async\\writeFile() chunks and yields' : '');
        $this->diags[] = Diagnostic::warning(
            $pf->path, $e->span->line, $e->span->column, 'async.blocking-io',
            $fn . '() ' . $what . ', which BLOCKS the whole scheduler'
            . ' (regular files have no readiness signal on either target)' . $hint
        );
    }
}
