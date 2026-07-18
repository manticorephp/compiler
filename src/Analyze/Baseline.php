<?php

namespace Analyze;

/**
 * phpstan-style baseline: a snapshot of KNOWN diagnostics to suppress, so a
 * strict analyzer can be adopted on a dirty codebase and gate only on NEW
 * findings. `--generate-baseline` writes the current set; `--baseline` filters
 * matches out of a later run.
 *
 * A diagnostic's signature is (file, code, message) — deliberately LINE-FREE so
 * an entry keeps matching after unrelated edits shift line numbers. The cost is
 * that a genuinely-new occurrence of an already-baselined message on the same
 * file is also suppressed; a fair trade for a stable baseline.
 */
final class Baseline
{
    public static function signature(Diagnostic $d): string
    {
        return $d->file . "\t" . $d->code . "\t" . $d->message;
    }

    /** @param Diagnostic[] $diags */
    public static function generate(array $diags): string
    {
        $out = '';
        foreach ($diags as $d) { $out = $out . self::signature($d) . "\n"; }
        return $out;
    }

    /**
     * @param Diagnostic[] $diags
     * @return Diagnostic[]
     */
    public static function filter(array $diags, string $baselineContent): array
    {
        /** @var array<string, bool> $set */
        $set = [];
        foreach (\explode("\n", $baselineContent) as $line) {
            if ($line === '') { continue; }
            $set[$line] = true;
        }
        /** @var Diagnostic[] $out */
        $out = [];
        foreach ($diags as $d) {
            if (isset($set[self::signature($d)])) { continue; }
            $out[] = $d;
        }
        return $out;
    }
}
