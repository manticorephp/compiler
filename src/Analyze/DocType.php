<?php

namespace Analyze;

/**
 * Extracts the type string a docblock associates with a `@param $x`,
 * `@return`, or `@var $x` tag. The one docblock tag scanner: the compiler
 * (`LowerTypes::docTagType`) and the analyzer both read docblocks through it.
 * A single forward scan, `<…>` / `(…)` / `{…}` / `callable(): T` aware; a tag quoted in a
 * backtick code span (`@var T` in prose) is not read as one.
 *
 * `$varName` is the bare name without `$` (`&$x` and `...$x` match it); pass ''
 * for `@return` (which has no variable). `$unnamedOnly` with `$varName === ''`
 * skips a tag that names a variable. Returns null when the tag is absent.
 */
final class DocType
{
    public static function tag(?string $doc, string $tag, string $varName, bool $unnamedOnly = false): ?string
    {
        if ($doc === null) { return null; }
        $n = \strlen($doc);
        $tlen = \strlen($tag);
        $i = 0;
        while ($i + $tlen <= $n) {
            if (\substr($doc, $i, $tlen) !== $tag || !self::isTagStart($doc, $i)) { $i = $i + 1; continue; }
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
                elseif ($c === '{') { $depth = $depth + 1; }
                elseif ($c === '}') { if ($depth > 0) { $depth = $depth - 1; } }
                elseif ($depth === 0 && $c === ':') { $j = self::skipSpaces($doc, $j + 1, $n); continue; }
                elseif ($depth === 0
                    && ($c === ' ' || $c === "\t" || $c === "\n" || $c === "\r")) {
                    break;
                }
                $j = $j + 1;
            }
            $type = \substr($doc, $typeStart, $j - $typeStart);
            if (\str_contains($type, '/*') || \str_contains($type, '//') || \str_contains($type, '#')) {
                $type = (string)\preg_replace('/\/\*.*?\*\\\\?\//s', '', $type);
                $type = (string)\preg_replace('/\/\/[^\r\n]*/', '', $type);
                $type = (string)\preg_replace('/#[^\r\n]*/', '', $type);
            }
            if (\str_contains($type, "\n")) {
                $type = (string)\preg_replace('/\r?\n[ \t]*\*?[ \t]*/', ' ', $type);
            }
            if ($varName === '' && !$unnamedOnly) { return $type; }
            $j = self::skipSpaces($doc, $j, $n);
            if ($varName === '') {
                if ($j < $n && \substr($doc, $j, 1) === '$') { $i = $j; continue; }
                return $type;
            }
            if ($j < $n && \substr($doc, $j, 1) === '&') { $j = $j + 1; }
            if ($j + 3 <= $n && \substr($doc, $j, 3) === '...') { $j = $j + 3; }
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

    /**
     * A tag follows whitespace, the `*` gutter or `/**`, and is not inside a backtick code span
     * of its line — prose that quotes `@var T` is not a tag; `/** Text. @param T $x *\/` is.
     */
    private static function isTagStart(string $doc, int $i): bool
    {
        if ($i > 0) {
            $p = \substr($doc, $i - 1, 1);
            if ($p !== ' ' && $p !== "\t" && $p !== '*' && $p !== "\n" && $p !== "\r") { return false; }
        }
        $ticks = 0;
        $k = $i - 1;
        while ($k >= 0) {
            $c = \substr($doc, $k, 1);
            if ($c === "\n") { break; }
            if ($c === '`') { $ticks = $ticks + 1; }
            $k = $k - 1;
        }
        return $ticks % 2 === 0;
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
