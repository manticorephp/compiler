<?php

/**
 * Additional PHP string functions, pure-PHP over the compiler's inline string
 * primitives. Global namespace so `stripos(...)` etc. resolve directly. These
 * live in the string domain only (no array-value re-storing, no callbacks), so
 * they are safe as stdlib externs — unlike the array helpers, whose element type
 * erases across the stdlib boundary and which live in the prelude.
 */

/** Case-insensitive {@see strpos}. Returns the offset or false. */
function stripos(string $haystack, string $needle, int $offset = 0): int|false
{
    return \strpos(\strtolower($haystack), \strtolower($needle), $offset);
}

/** Case-insensitive {@see str_replace}, scalar search/replace/subject (PHP
 *  `str_ireplace`). Matches are found case-insensitively; the ORIGINAL casing of
 *  the non-matched text is preserved. */
function str_ireplace(string $search, string $replace, string $subject): string
{
    if ($search === '') { return $subject; }
    $ls = \strtolower($subject);
    $ln = \strtolower($search);
    $sl = \strlen($search);
    $out = "";
    $pos = 0;
    while (true) {
        $hit = \strpos($ls, $ln, $pos);
        if ($hit === false) { $out = $out . \substr($subject, $pos); break; }
        $h = (int)$hit;
        $out = $out . \substr($subject, $pos, $h - $pos) . $replace;
        $pos = $h + $sl;
    }
    return $out;
}

/**
 * Wrap `$string` to lines of at most `$width` bytes, breaking at spaces with
 * `$break` (PHP `wordwrap`). With `$cut`, a word longer than `$width` is split;
 * otherwise it overflows the line. Existing `$break`/newline runs are respected.
 */
function wordwrap(string $string, int $width = 75, string $break = "\n", bool $cut = false): string
{
    if ($width < 1) { return $string; }
    $n = \strlen($string);
    $out = "";
    $lineStart = 0;
    $lastSpace = -1;
    $i = 0;
    while ($i < $n) {
        $c = $string[$i];
        if ($c === "\n") {
            $out = $out . \substr($string, $lineStart, $i - $lineStart + 1);
            $lineStart = $i + 1;
            $lastSpace = -1;
            $i = $i + 1;
            continue;
        }
        if ($c === " ") { $lastSpace = $i; }
        if ($i - $lineStart >= $width) {
            if ($lastSpace >= $lineStart) {
                $out = $out . \substr($string, $lineStart, $lastSpace - $lineStart) . $break;
                $lineStart = $lastSpace + 1;
                $lastSpace = -1;
            } elseif ($cut) {
                $out = $out . \substr($string, $lineStart, $width) . $break;
                $lineStart = $lineStart + $width;
                $lastSpace = -1;
            }
        }
        $i = $i + 1;
    }
    return $out . \substr($string, $lineStart);
}

/**
 * Number of matching characters between `$string1` and `$string2` (PHP
 * `similar_text`, 2-arg form): the longest common substring plus, recursively,
 * the same over the segments to its left and right.
 */
function similar_text(string $string1, string $string2): int
{
    $la = \strlen($string1);
    $lb = \strlen($string2);
    if ($la === 0 || $lb === 0) { return 0; }
    $max = 0;
    $pa = 0;
    $pb = 0;
    for ($i = 0; $i < $la; $i = $i + 1) {
        for ($j = 0; $j < $lb; $j = $j + 1) {
            $k = 0;
            while ($i + $k < $la && $j + $k < $lb && $string1[$i + $k] === $string2[$j + $k]) {
                $k = $k + 1;
            }
            if ($k > $max) { $max = $k; $pa = $i; $pb = $j; }
        }
    }
    if ($max === 0) { return 0; }
    $sum = $max;
    $sum = $sum + similar_text(\substr($string1, 0, $pa), \substr($string2, 0, $pb));
    $sum = $sum + similar_text(\substr($string1, $pa + $max), \substr($string2, $pb + $max));
    return $sum;
}

/** Levenshtein edit distance between `$string1` and `$string2` (insertions,
 *  deletions and substitutions each cost 1). */
function levenshtein(string $string1, string $string2): int
{
    $la = \strlen($string1);
    $lb = \strlen($string2);
    if ($la === 0) { return $lb; }
    if ($lb === 0) { return $la; }
    $prev = [];
    for ($j = 0; $j <= $lb; $j = $j + 1) { $prev[$j] = $j; }
    for ($i = 1; $i <= $la; $i = $i + 1) {
        $cur = [];
        $cur[0] = $i;
        for ($j = 1; $j <= $lb; $j = $j + 1) {
            $cost = $string1[$i - 1] === $string2[$j - 1] ? 0 : 1;
            $del = $prev[$j] + 1;
            $ins = $cur[$j - 1] + 1;
            $sub = $prev[$j - 1] + $cost;
            $m = $del;
            if ($ins < $m) { $m = $ins; }
            if ($sub < $m) { $m = $sub; }
            $cur[$j] = $m;
        }
        $prev = $cur;
    }
    return $prev[$lb];
}

/** Case-insensitive {@see strrpos}. */
function strripos(string $haystack, string $needle, int $offset = 0): int|false
{
    return \strrpos(\strtolower($haystack), \strtolower($needle), $offset);
}

/** Case-insensitive {@see strstr}: the haystack from the first match of
 *  `$needle` (or before it when `$before_needle`), or false. */
function stristr(string $haystack, string $needle, bool $before_needle = false): string|false
{
    $p = \strpos(\strtolower($haystack), \strtolower($needle));
    if ($p === false) { return false; }
    if ($before_needle) { return \substr($haystack, 0, $p); }
    return \substr($haystack, $p);
}

/** The `$string` from the first byte that occurs in `$characters` to the end,
 *  or false when none does (PHP `strpbrk`). */
function strpbrk(string $string, string $characters): string|false
{
    $n = \strlen($string);
    for ($i = 0; $i < $n; $i = $i + 1) {
        if (\strpos($characters, $string[$i]) !== false) {
            return \substr($string, $i);
        }
    }
    return false;
}

/** Length of the initial segment of `$subject` consisting only of bytes in
 *  `$mask` (PHP `strspn`; the complement of the `strcspn` builtin). */
function strspn(string $subject, string $mask, int $offset = 0): int
{
    $n = \strlen($subject);
    if ($offset < 0) { $offset = $n + $offset; }
    if ($offset < 0) { $offset = 0; }
    $i = $offset;
    while ($i < $n) {
        if (\strpos($mask, $subject[$i]) === false) { break; }
        $i = $i + 1;
    }
    return $i - $offset;
}

/**
 * Replace the substring of `$string` at `$offset` (length `$length`, default to
 * the end) with `$replace` (PHP `substr_replace`, scalar form). A negative
 * offset/length counts from the end.
 */
function substr_replace(string $string, string $replace, int $offset, int $length = \PHP_INT_MAX): string
{
    $n = \strlen($string);
    if ($offset < 0) { $offset = $n + $offset; if ($offset < 0) { $offset = 0; } }
    elseif ($offset > $n) { $offset = $n; }
    $rest = $n - $offset;
    if ($length < 0) {
        $len = $rest + $length;
        if ($len < 0) { $len = 0; }
    } elseif ($length > $rest) {
        $len = $rest;                 // default PHP_INT_MAX or an overlong span → to end
    } else {
        $len = $length;
    }
    return \substr($string, 0, $offset) . $replace . \substr($string, $offset + $len);
}

/** Split `$string` into `$length`-byte chunks each followed by `$separator`
 *  (PHP `chunk_split`). */
function chunk_split(string $string, int $length = 76, string $separator = "\r\n"): string
{
    if ($length < 1) { $length = 1; }
    $n = \strlen($string);
    $out = "";
    $i = 0;
    while ($i < $n) {
        $out = $out . \substr($string, $i, $length) . $separator;
        $i = $i + $length;
    }
    return $out;
}

/** Backslash-escape the regex metacharacters `. \ + * ? [ ^ ] $ ( )` (PHP
 *  `quotemeta`). */
function quotemeta(string $string): string
{
    $n = \strlen($string);
    $out = "";
    for ($i = 0; $i < $n; $i = $i + 1) {
        $c = $string[$i];
        if ($c === '.' || $c === '\\' || $c === '+' || $c === '*' || $c === '?'
            || $c === '[' || $c === '^' || $c === ']' || $c === '$'
            || $c === '(' || $c === ')') {
            $out = $out . '\\';
        }
        $out = $out . $c;
    }
    return $out;
}

/** Lowercase-hex encoding of each byte (PHP `bin2hex`). */
function bin2hex(string $string): string
{
    $d = "0123456789abcdef";
    $n = \strlen($string);
    $out = "";
    for ($i = 0; $i < $n; $i = $i + 1) {
        $o = \ord($string[$i]);
        $out = $out . $d[($o >> 4) & 15] . $d[$o & 15];
    }
    return $out;
}

/** Decode a hex string back to bytes (PHP `hex2bin`). */
function hex2bin(string $string): string
{
    $n = \strlen($string);
    $out = "";
    $i = 0;
    while ($i + 1 < $n) {
        $out = $out . \chr((__mc_hexval($string[$i]) << 4) | __mc_hexval($string[$i + 1]));
        $i = $i + 2;
    }
    return $out;
}

/** Hex-digit value of a single byte, 0 for a non-digit. */
function __mc_hexval(string $c): int
{
    $o = \ord($c);
    if ($o >= 48 && $o <= 57) { return $o - 48; }
    if ($o >= 97 && $o <= 102) { return $o - 87; }
    if ($o >= 65 && $o <= 70) { return $o - 55; }
    return 0;
}

/** ROT13 the ASCII letters, pass everything else through (PHP `str_rot13`). */
function str_rot13(string $string): string
{
    $n = \strlen($string);
    $out = "";
    for ($i = 0; $i < $n; $i = $i + 1) {
        $o = \ord($string[$i]);
        if ($o >= 65 && $o <= 90) { $o = ($o - 65 + 13) % 26 + 65; }
        elseif ($o >= 97 && $o <= 122) { $o = ($o - 97 + 13) % 26 + 97; }
        $out = $out . \chr($o);
    }
    return $out;
}

/** Un-escape a backslash-quoted string (PHP `stripslashes`): `\\`→`\`, and a
 *  backslash before any other byte is dropped, keeping that byte. */
function stripslashes(string $string): string
{
    $n = \strlen($string);
    $out = "";
    $i = 0;
    while ($i < $n) {
        if ($string[$i] === '\\' && $i + 1 < $n) {
            $i = $i + 1;
        }
        $out = $out . $string[$i];
        $i = $i + 1;
    }
    return $out;
}

/**
 * `strtr($string, $from, $to)` — translate each byte present in `$from` to the
 * byte at the same index in `$to` (only up to the shorter of the two). The
 * two-argument array form (`strtr($s, [$search => $replace, …])`) is not modelled
 * here — a stdlib extern would erase the pairs array's element type.
 */
function strtr(string $string, string $from, string $to): string
{
    $m = \strlen($from);
    $mt = \strlen($to);
    if ($mt < $m) { $m = $mt; }
    $n = \strlen($string);
    $out = "";
    for ($i = 0; $i < $n; $i = $i + 1) {
        $c = $string[$i];
        $rep = $c;
        for ($j = 0; $j < $m; $j = $j + 1) {
            if ($from[$j] === $c) { $rep = $to[$j]; break; }
        }
        $out = $out . $rep;
    }
    return $out;
}

/**
 * HTML-escape for `htmlspecialchars` with php 8.1+ default flags
 * (ENT_QUOTES | ENT_HTML401): `&`→`&amp;`, `<`→`&lt;`, `>`→`&gt;`,
 * `"`→`&quot;`, `'`→`&#039;`.
 */
function htmlspecialchars(string $string): string
{
    $n = \strlen($string);
    $out = "";
    for ($i = 0; $i < $n; $i = $i + 1) {
        $c = $string[$i];
        if ($c === '&') { $out = $out . '&amp;'; }
        elseif ($c === '<') { $out = $out . '&lt;'; }
        elseif ($c === '>') { $out = $out . '&gt;'; }
        elseif ($c === '"') { $out = $out . '&quot;'; }
        elseif ($c === "'") { $out = $out . '&#039;'; }
        else { $out = $out . $c; }
    }
    return $out;
}

/**
 * Rewrite C-style printf exponents (`1.5e+03`) to PHP style (`1.5e+3`): strip
 * leading zeros from the exponent digits, keeping at least one. Called by the
 * sprintf/printf codegen builtin when the format has an `%e`/`%E`/`%g`/`%G`
 * conversion (C always pads the exponent to two digits; PHP uses the minimum).
 */
function __mc_fix_exp(string $s): string
{
    $out = '';
    $n = strlen($s);
    $i = 0;
    while ($i < $n) {
        $c = $s[$i];
        $out = $out . $c;
        $i = $i + 1;
        if ($c === 'e' || $c === 'E') {
            if ($i < $n && ($s[$i] === '+' || $s[$i] === '-')) {
                $out = $out . $s[$i];
                $i = $i + 1;
                // Drop a leading '0' only while another digit still follows,
                // so a lone `e+0` keeps its zero.
                while ($i + 1 < $n && $s[$i] === '0'
                    && $s[$i + 1] >= '0' && $s[$i + 1] <= '9') {
                    $i = $i + 1;
                }
            }
        }
    }
    return $out;
}
