<?php

namespace Analyze;

/**
 * Extracts the type string a docblock associates with a `@param $x`,
 * `@return`, or `@var $x` tag. A faithful port of the compiler's own
 * `LowerTypes::docTagType` single-forward-scan (same `<…>` / `(…)` /
 * `callable(): T` awareness), so the analyzer reads docblocks exactly the way
 * the codegen does — no second, diverging interpretation.
 *
 * `$varName` is the bare name without `$`; pass '' for `@return` (which has no
 * variable). Returns null when the tag is absent.
 */
final class DocType
{
    public static function tag(?string $doc, string $tag, string $varName): ?string
    {
        if ($doc === null) { return null; }
        $n = \strlen($doc);
        $tlen = \strlen($tag);
        $i = 0;
        while ($i + $tlen <= $n) {
            if (\substr($doc, $i, $tlen) !== $tag) { $i = $i + 1; continue; }
            $j = $i + $tlen;
            $b = ($j < $n) ? \substr($doc, $j, 1) : '';
            if ($b !== ' ' && $b !== "\t") { $i = $i + 1; continue; }
            while ($j < $n) {
                $c = \substr($doc, $j, 1);
                if ($c !== ' ' && $c !== "\t") { break; }
                $j = $j + 1;
            }
            $typeStart = $j;
            $depth = 0;
            while ($j < $n) {
                $c = \substr($doc, $j, 1);
                if ($c === '<') { $depth = $depth + 1; }
                elseif ($c === '>') { if ($depth > 0) { $depth = $depth - 1; } }
                elseif ($c === '(') { $depth = $depth + 1; }
                elseif ($c === ')') { if ($depth > 0) { $depth = $depth - 1; } }
                elseif ($depth === 0 && $c === ':') { $j = self::skipSpaces($doc, $j + 1, $n); continue; }
                elseif ($depth === 0
                    && ($c === ' ' || $c === "\t" || $c === "\n" || $c === "\r")) {
                    break;
                }
                $j = $j + 1;
            }
            $type = \substr($doc, $typeStart, $j - $typeStart);
            if ($varName === '') { return $type; }
            while ($j < $n) {
                $c = \substr($doc, $j, 1);
                if ($c !== ' ' && $c !== "\t") { break; }
                $j = $j + 1;
            }
            $want = '$' . $varName;
            $wl = \strlen($want);
            if ($j + $wl <= $n && \substr($doc, $j, $wl) === $want) {
                $after = ($j + $wl < $n) ? \substr($doc, $j + $wl, 1) : ' ';
                if ($after === ' ' || $after === "\t"
                    || $after === "\n" || $after === "\r") {
                    return $type;
                }
            }
            $i = $j;
        }
        return null;
    }

    private static function skipSpaces(string $doc, int $j, int $n): int
    {
        while ($j < $n) {
            $c = \substr($doc, $j, 1);
            if ($c !== ' ' && $c !== "\t") { break; }
            $j = $j + 1;
        }
        return $j;
    }
}
