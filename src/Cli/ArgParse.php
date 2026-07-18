<?php

namespace Cli;

/**
 * A tiny declarative argument parser — the one place CLI flags are decoded,
 * replacing the hand-rolled per-command loops. Unlike `getopt()` it does NOT
 * stop at the first non-option, so options and positionals may be interleaved
 * (`compile prog.php -o prog`), which is how the CLI and the test suite call it.
 *
 * A spec maps each option NAME (no leading dashes) to {@see VALUE} or {@see FLAG}.
 * Accepted forms:
 *   -o out        short value, space-separated
 *   -O2           short value, attached
 *   --memory=rc   long value, `=`-joined
 *   --memory rc   long value, space-separated
 *   --deep        long flag
 * Anything not starting with `-` is a positional. An unrecognized option or a
 * value option with no value sets {@see ParsedArgs::$error}.
 */
final class ArgParse
{
    public const VALUE = 'value';
    public const FLAG = 'flag';

    /**
     * @param string[]              $args
     * @param array<string, string> $spec  option name → self::VALUE | self::FLAG
     */
    public static function parse(array $args, array $spec): ParsedArgs
    {
        /** @var array<string, bool> $flags */
        $flags = [];
        /** @var array<string, string> $values */
        $values = [];
        /** @var string[] $positional */
        $positional = [];

        $i = 0;
        $n = \count($args);
        while ($i < $n) {
            $a = $args[$i];
            $i = $i + 1;

            if ($a === '' || \substr($a, 0, 1) !== '-' || $a === '-') {
                $positional[] = $a;
                continue;
            }

            $name = '';
            $inline = '';
            $hasInline = false;
            if (\substr($a, 0, 2) === '--') {
                $rest = \substr($a, 2, \strlen($a) - 2);
                $eq = \strpos($rest, '=');
                if ($eq !== false) {
                    $name = \substr($rest, 0, $eq);
                    $inline = \substr($rest, $eq + 1, \strlen($rest) - ($eq + 1));
                    $hasInline = true;
                } else {
                    $name = $rest;
                }
            } else {
                $name = \substr($a, 1, 1);
                if (\strlen($a) > 2) {
                    $inline = \substr($a, 2, \strlen($a) - 2);
                    $hasInline = true;
                }
            }

            $kind = $spec[$name] ?? null;
            if ($kind === null) {
                return new ParsedArgs($flags, $values, $positional, 'unknown flag: ' . $a);
            }
            if ($kind === self::FLAG) {
                $flags[$name] = true;
                continue;
            }
            // VALUE.
            if ($hasInline) {
                $values[$name] = $inline;
                continue;
            }
            if ($i >= $n) {
                return new ParsedArgs($flags, $values, $positional, 'missing value for ' . $a);
            }
            $values[$name] = $args[$i];
            $i = $i + 1;
        }

        return new ParsedArgs($flags, $values, $positional, null);
    }
}
