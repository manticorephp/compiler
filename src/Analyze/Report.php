<?php

namespace Analyze;

/**
 * Renders a diagnostic set for the terminal. Compiler-style
 * `path:line:col: severity: message` lines followed by a one-line summary, so
 * the output reads like `clang` / `gcc` and editors can jump to a location.
 *
 * A JSON renderer for editor/CI integration is a later phase; this is the
 * default human format.
 */
final class Report
{
    /**
     * Stable insertion sort by (file, line, col). Public so a caller that appends
     * more diagnostics after the analyzer ran (e.g. the deep MIR pass) can
     * re-order the combined set.
     *
     * @param Diagnostic[] $diags
     * @return Diagnostic[]
     */
    public static function sortDiags(array $diags): array
    {
        $n = \count($diags);
        $i = 1;
        while ($i < $n) {
            $key = $diags[$i];
            $j = $i - 1;
            while ($j >= 0 && self::after($diags[$j], $key)) {
                $diags[$j + 1] = $diags[$j];
                $j = $j - 1;
            }
            $diags[$j + 1] = $key;
            $i = $i + 1;
        }
        return $diags;
    }

    private static function after(Diagnostic $a, Diagnostic $b): bool
    {
        if ($a->file !== $b->file) { return \strcmp($a->file, $b->file) > 0; }
        if ($a->line !== $b->line) { return $a->line > $b->line; }
        return $a->col > $b->col;
    }

    /**
     * Machine-readable output for editors / CI: a JSON array of
     * `{file,line,col,severity,code,message}`. Built by hand (not `json_encode`
     * on a mixed-value assoc, which the self-host compiler flattens) with a
     * minimal string escaper — UTF-8 bytes pass through unescaped, which JSON
     * permits.
     *
     * @param Diagnostic[] $diags
     */
    public static function json(array $diags): string
    {
        $out = '[';
        $first = true;
        foreach ($diags as $d) {
            if (!$first) { $out = $out . ','; }
            $first = false;
            $out = $out . '{"file":' . self::jsonStr($d->file)
                . ',"line":' . (string)$d->line
                . ',"col":' . (string)$d->col
                . ',"severity":' . self::jsonStr($d->severity)
                . ',"code":' . self::jsonStr($d->code)
                . ',"message":' . self::jsonStr($d->message)
                . '}';
        }
        return $out . "]\n";
    }

    private static function jsonStr(string $s): string
    {
        $out = '"';
        $n = \strlen($s);
        $i = 0;
        while ($i < $n) {
            $c = \substr($s, $i, 1);
            if ($c === '"') { $out = $out . '\\"'; }
            elseif ($c === '\\') { $out = $out . '\\\\'; }
            elseif ($c === "\n") { $out = $out . '\\n'; }
            elseif ($c === "\r") { $out = $out . '\\r'; }
            elseif ($c === "\t") { $out = $out . '\\t'; }
            else { $out = $out . $c; }
            $i = $i + 1;
        }
        return $out . '"';
    }

    /** @param Diagnostic[] $diags */
    public static function human(array $diags): string
    {
        if (\count($diags) === 0) {
            return "No errors found.\n";
        }
        $out = '';
        $errors = 0;
        $warnings = 0;
        foreach ($diags as $d) {
            $out = $out . $d->file . ':' . (string)$d->line . ':' . (string)$d->col
                . ': ' . $d->severity . ': ' . $d->message . "\n";
            if ($d->severity === Diagnostic::SEV_ERROR) {
                $errors = $errors + 1;
            } else {
                $warnings = $warnings + 1;
            }
        }
        $out = $out . "\nFound " . (string)$errors . ' error(s), '
            . (string)$warnings . " warning(s).\n";
        return $out;
    }
}
