<?php

namespace Analyze\Rules;

use Analyze\AstWalk;
use Analyze\Diagnostic;
use Analyze\ParsedFile;
use Parser\Ast\CallExpr;
use Parser\Ast\StringLiteral;

/**
 * Filesystem calls the blocking pool does NOT cover stop the WHOLE loop.
 *
 * `O_NONBLOCK` is a no-op for regular files on Linux and macOS alike. Under the
 * scheduler most file calls run on the blocking-offload pool (docs/async.md,
 * "Blocking calls run on a pool") — fopen/fread/fwrite, file_get_contents,
 * file_put_contents, file(), readfile(), the stat family, the directory calls —
 * and the task parks while the loop keeps going. What stays inline is reported:
 *   - glob(), a directory walk with no pooled form,
 *   - copy() on a literal filesystem path (both ends are plain libc stdio).
 * fgets / stream_get_contents / file_exists / is_readable are inline too, but take
 * a handle or are a single cheap `access`, so nothing is provable from the call.
 * A computed or `scheme://` path is left alone — guessing would make the rule
 * noise, and a lint nobody trusts gets switched off.
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

            if ($fn === 'glob') {
                $this->report($pf, $e, $fn, 'walks the filesystem');
                continue;
            }
            if ($fn !== 'copy') { continue; }
            if (\count($e->args) === 0) { continue; }
            $path = $e->args[0];
            if (!($path instanceof StringLiteral)) { continue; }
            $lit = $this->literalText($path);
            if (\strpos($lit, '://') !== false) { continue; }
            $this->report($pf, $e, $fn, "reads '" . $lit . "' on the filesystem");
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
        $hint = $fn === 'copy' ? ' — Async\\readFile() + Async\\writeFile() chunk and yield' : '';
        $this->diags[] = Diagnostic::warning(
            $pf->path, $e->span->line, $e->span->column, 'async.blocking-io',
            $fn . '() ' . $what . ' inline, which BLOCKS the whole scheduler'
            . ' (the blocking pool does not cover it)' . $hint
        );
    }
}
