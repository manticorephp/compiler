<?php

namespace Analyze;

use Parser\ParseResult;

/** The one mapping from tolerant-parse errors to `parse.error` diagnostics (CLI and Semantic\Workspace). */
final class ParseDiagnostics
{
    /** @return Diagnostic[] */
    public static function of(string $file, ParseResult $r): array
    {
        /** @var Diagnostic[] $out */
        $out = [];
        foreach ($r->errors as $pe) {
            $d = Diagnostic::error($file, $pe->errLine, $pe->column, 'parse.error', $pe->reason);
            $d->startLine = $pe->errLine;
            $d->startCol = $pe->column;
            $d->endLine = $pe->endLine;
            $d->endCol = $pe->endColumn;
            $out[] = $d;
        }
        return $out;
    }
}
