<?php

namespace Lsp;

use Analyze\Diagnostic;

final class DiagnosticMap
{
    /**
     * @return array<string, mixed> LSP Diagnostic. $lineTextStart / $lineTextEnd are the texts of the start and end lines (UTF-16 conversion).
     */
    public static function toLsp(Diagnostic $d, string $lineTextStart, string $lineTextEnd): array
    {
        if ($d->startLine === 0) {
            $sl = $d->line;
            $sc = $d->col;
            $el = $sl;
            $ec = $sc + 1;
            $lineTextEnd = $lineTextStart;
        } else {
            $sl = $d->startLine;
            $sc = $d->startCol;
            $el = $d->endLine;
            $ec = $d->endCol;
        }
        $a = Position::toLsp($lineTextStart, $sc);
        $b = Position::toLsp($lineTextEnd, $ec);
        return [
            'range' => [
                'start' => ['line' => \max(0, $sl - 1), 'character' => $a],
                'end' => ['line' => \max(0, $el - 1), 'character' => $b],
            ],
            'severity' => $d->severity === Diagnostic::SEV_ERROR ? 1 : 2,
            'code' => $d->code,
            'source' => 'manticore',
            'message' => $d->message,
        ];
    }
}
