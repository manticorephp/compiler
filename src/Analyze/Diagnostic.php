<?php

namespace Analyze;

/**
 * One analyzer finding. Location is source-faithful (`file:line:col`) because
 * the analyzer runs on the AST, where {@see \Parser\Ast\Span} still carries both
 * line and column — unlike the MIR, which keeps line only.
 *
 * `$code` is a stable machine slug (`arg.type`, `undefined.function`,
 * `repr.cell-erasure`, …) for editor/CI consumers; `$message` is the
 * human-readable sentence. `$severity` is {@see SEV_ERROR} or {@see SEV_WARNING}
 * — only an error flips the command's exit code.
 */
final class Diagnostic
{
    public const SEV_ERROR = 'error';
    public const SEV_WARNING = 'warning';

    public function __construct(
        public string $file,
        public int $line,
        public int $col,
        public string $severity,
        public string $code,
        public string $message,
        public int $startLine = 0,
        public int $startCol = 0,
        public int $endLine = 0,
        public int $endCol = 0,
    ) {}

    /** Anchor stays the span's line/column; start/end come from the span's range (0 when unranged). */
    public static function at(string $file, \Parser\Ast\Span $span, string $severity, string $code, string $message): Diagnostic
    {
        $d = new Diagnostic($file, $span->line, $span->column, $severity, $code, $message);
        return $d->withRange($span);
    }

    public function withRange(\Parser\Ast\Span $span): Diagnostic
    {
        $this->startLine = $span->startLine();
        $this->startCol = $span->startColumn();
        $this->endLine = $span->endLine();
        $this->endCol = $span->endColumn();
        return $this;
    }

    public static function error(string $file, int $line, int $col, string $code, string $message): Diagnostic
    {
        return new Diagnostic($file, $line, $col, self::SEV_ERROR, $code, $message);
    }

    public static function warning(string $file, int $line, int $col, string $code, string $message): Diagnostic
    {
        return new Diagnostic($file, $line, $col, self::SEV_WARNING, $code, $message);
    }
}
