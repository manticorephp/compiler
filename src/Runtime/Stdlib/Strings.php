<?php

/**
 * Pure-PHP implementations of common PHP string std functions on top
 * of libc primitives bound via #[Ffi\Library, Symbol] in
 * {@see Runtime\Libc}. No Rust runtime, no external libs.
 *
 * Naming: the symbols here live in the GLOBAL namespace (no `namespace`
 * declaration) so PHP code calling `str_starts_with($x, $y)` resolves
 * to these directly. The compiler's `tryCompileBuiltin` table is the
 * gate keeper — anything it does NOT handle inline falls through to
 * the user-function resolution path, which then finds these.
 *
 * Calls into `Runtime\Libc` use fully-qualified names because the
 * parser doesn't yet wire `use function` into a per-file alias table.
 */

function str_starts_with(string $haystack, string $needle): bool
{
    $nLen = \Runtime\Libc\strlen($needle);
    if ($nLen === 0) {
        return true;
    }
    $hLen = \Runtime\Libc\strlen($haystack);
    if ($nLen > $hLen) {
        return false;
    }
    return \Runtime\Libc\memcmp($haystack, $needle, $nLen) === 0;
}

function str_ends_with(string $haystack, string $needle): bool
{
    $nLen = \Runtime\Libc\strlen($needle);
    if ($nLen === 0) {
        return true;
    }
    $hLen = \Runtime\Libc\strlen($haystack);
    if ($nLen > $hLen) {
        return false;
    }
    // `substr` is a compiler-inline builtin — gives us a fresh
    // NUL-terminated tail without FFI pointer math.
    return \Runtime\Libc\memcmp(\substr($haystack, $hLen - $nLen), $needle, $nLen) === 0;
}

function str_contains(string $haystack, string $needle): bool
{
    $nLen = \strlen($needle);
    if ($nLen === 0) {
        return true;
    }
    // memcmp scan instead of libc `strstr` (whose `Ffi\Ptr` return + the
    // `!== null` ptr-domain compare mis-infers under self-host — the
    // parser's namespace qualification depends on this answer). Mirrors
    // str_starts_with / str_ends_with, which use the same byte-compare.
    $hLen = \strlen($haystack);
    if ($nLen > $hLen) {
        return false;
    }
    $last = $hLen - $nLen;
    for ($i = 0; $i <= $last; $i = $i + 1) {
        if (\Runtime\Libc\memcmp(\substr($haystack, $i, $nLen), $needle, $nLen) === 0) {
            return true;
        }
    }
    return false;
}

/**
 * Like PHP's `trim`/`ltrim`/`rtrim`. Default mask is " \t\n\r\0\v"
 * — the historic PHP "whitespace + NUL" set.
 */
/** Internal: true iff byte $b appears in $mask. Linear scan. */
function __mask_has_byte(string $mask, int $b): bool
{
    $mLen = \strlen($mask);
    for ($j = 0; $j < $mLen; $j = $j + 1) {
        if (\ord($mask[$j]) === $b) { return true; }
    }
    return false;
}

function ltrim(string $s, string $mask = " \t\n\r\0\x0B"): string
{
    $len = \strlen($s);
    $i = 0;
    while ($i < $len) {
        if (!__mask_has_byte($mask, \ord($s[$i]))) { break; }
        $i = $i + 1;
    }
    return \substr($s, $i, $len - $i);
}

function rtrim(string $s, string $mask = " \t\n\r\0\x0B"): string
{
    $len = \strlen($s);
    $i = $len;
    while ($i > 0) {
        if (!__mask_has_byte($mask, \ord($s[$i - 1]))) { break; }
        $i = $i - 1;
    }
    return \substr($s, 0, $i);
}

function trim(string $s, string $mask = " \t\n\r\0\x0B"): string
{
    return rtrim(ltrim($s, $mask), $mask);
}

/**
 * `strrpos` — last occurrence of needle in haystack, or false.
 * Walks haystack right-to-left so single-character needles match
 * the cheap byte path; multi-character needles fall to memcmp.
 */
/**
 * Index of the last `$needle` occurrence in `$haystack`, or -1 when
 * not found. PHP's official signature returns int|false; we collapse
 * to -1 because the compiler's union-typing for return values is
 * still in flight. Callers that need PHP-strict semantics can wrap.
 */
function strrpos(string $haystack, string $needle, int $offset = 0): int
{
    $hLen = \strlen($haystack);
    $nLen = \strlen($needle);
    if ($nLen === 0) { return -1; }
    if ($nLen > $hLen) { return -1; }
    $start = $hLen - $nLen;
    if ($offset > 0 && $offset > $start) { return -1; }
    if ($offset < 0) {
        $start = \max(0, $hLen + $offset - $nLen);
    }
    for ($i = $start; $i >= 0; $i = $i - 1) {
        if (\Runtime\Libc\memcmp(\substr($haystack, $i, $nLen), $needle, $nLen) === 0) {
            return $i;
        }
    }
    return -1;
}

/** Same `-1`-for-not-found convention as the inline 2-arg builtin. */
function strpos(string $haystack, string $needle, int $offset = 0): int
{
    // Self-host robustness: the `?string` → `string` coerce sometimes
    // hands us a null ptr that the strict-equal guards upstream
    // miss. Belt-and-braces — treat null haystack/needle as "no
    // match" rather than feeding it to libc strlen.
    if ($haystack === null || $needle === null) { return -1; }
    $hLen = \strlen($haystack);
    $nLen = \strlen($needle);
    if ($nLen === 0) { return -1; }
    if ($offset >= $hLen) { return -1; }
    if ($offset < 0) { $offset = \max(0, $hLen + $offset); }
    for ($i = $offset; $i + $nLen <= $hLen; $i = $i + 1) {
        if (\Runtime\Libc\memcmp(\substr($haystack, $i, $nLen), $needle, $nLen) === 0) {
            return $i;
        }
    }
    return -1;
}

/**
 * `str_replace` — PHP `array|string $search, array|string $replace`. An array
 * search applies each pair in order (a scalar `$replace` is used for every
 * search; an array `$replace` is positional, missing entries → ''). A scalar
 * search delegates to the single-pair worker. The union params are NaN-boxed
 * cells; `is_array` dispatches, and a scalar arm coerces the cell to string.
 *
 * @param array|string $search
 * @param array|string $replace
 */
function str_replace(array|string $search, array|string $replace, string $subject): string
{
    if (is_array($search)) {
        $out = $subject;
        $n = \count($search);
        $repIsArr = is_array($replace);
        $i = 0;
        while ($i < $n) {
            $rep = $repIsArr ? (string)($replace[$i] ?? '') : (string)$replace;
            $out = __mir_str_replace_one((string)$search[$i], $rep, $out);
            $i = $i + 1;
        }
        return $out;
    }
    return __mir_str_replace_one((string)$search, (string)$replace, $subject);
}

/**
 * Single search/replace pair worker. Walks haystack left-to-right, appending
 * the next chunk from haystack and the replacement, until no more matches.
 * Linear in haystack length.
 */
function __mir_str_replace_one(string $search, string $replace, string $subject): string
{
    if ($search === '') { return $subject; }
    // `strpos` is the native (strstr-based) builtin: a real substring
    // search, O(n) with NO per-byte `substr` allocation. `=== false` is
    // the correct miss test on its tagged `int|false` return (the old
    // `$hit < 0` misread the boxed false, hence the former memcmp walk).
    //
    // CHUNK-based output: copy the whole run between matches in one
    // substr/concat — O(matches) appends, not byte-at-a-time. The `$out =
    // $out . …` self-appends are amortized in place (capacity header), so
    // this is O(n) total regardless of subject length.
    $sLen = \strlen($search);
    $subLen = \strlen($subject);
    if ($sLen > $subLen) { return $subject; }
    $out = '';
    $chunkStart = 0;
    while (true) {
        $hit = \strpos($subject, $search, $chunkStart);
        if ($hit === false) { break; }
        if ($hit > $chunkStart) {
            $out = $out . \substr($subject, $chunkStart, $hit - $chunkStart);
        }
        $out = $out . $replace;
        $chunkStart = $hit + $sLen;
    }
    if ($chunkStart < $subLen) {
        $out = $out . \substr($subject, $chunkStart, $subLen - $chunkStart);
    }
    return $out;
}

// `explode` lives in the PRELUDE (prelude/array_fns.php), not here: compiled
// standalone in the stdlib .o its bare-`array` return erased the element and
// box-tagged each segment into a vec[cell] (~0.7x php). Injected with the user
// program (gated on `explode(` in Main.php) it return-narrows to vec[string].

/** Uppercase the first character (1:1 with PHP `ucfirst`). */
function ucfirst(string $s): string
{
    if (\strlen($s) === 0) { return $s; }
    return \strtoupper(\substr($s, 0, 1)) . \substr($s, 1);
}

/** Lowercase the first character (1:1 with PHP `lcfirst`). */
function lcfirst(string $s): string
{
    if (\strlen($s) === 0) { return $s; }
    return \strtolower(\substr($s, 0, 1)) . \substr($s, 1);
}

/** Uppercase the first letter of each word (PHP `ucwords`, default delimiters
 *  whitespace " \t\r\n\f\v"). */
function ucwords(string $s): string
{
    $n = \strlen($s);
    $out = "";
    $cap = true;
    $delims = " \t\r\n\x0C\x0B";
    for ($i = 0; $i < $n; $i = $i + 1) {
        $c = \substr($s, $i, 1);
        if ($cap) { $out = $out . \strtoupper($c); } else { $out = $out . $c; }
        $cap = \strpos($delims, $c) !== false;
    }
    return $out;
}

/** Count non-overlapping occurrences of `$needle` in `$haystack` (PHP
 *  `substr_count`). */
function substr_count(string $haystack, string $needle): int
{
    if ($needle === "") { return 0; }
    $count = 0;
    $nl = \strlen($needle);
    $pos = \strpos($haystack, $needle, 0);
    while ($pos !== false) {
        $count = $count + 1;
        $pos = \strpos($haystack, $needle, $pos + $nl);
    }
    return $count;
}

/** Reverse a string byte-wise (1:1 with PHP `strrev`). */
function strrev(string $s): string
{
    $i = \strlen($s) - 1;
    $out = "";
    while ($i >= 0) {
        $out = $out . \substr($s, $i, 1);
        $i = $i - 1;
    }
    return $out;
}

/**
 * Pad a string to `$len` with `$pad`. `$type`: 0 = STR_PAD_LEFT,
 * 1 = STR_PAD_RIGHT (default), 2 = STR_PAD_BOTH — matching PHP's
 * constant values, so `str_pad($s, $n, $p, STR_PAD_LEFT)` works.
 */
function str_pad(string $s, int $len, string $pad = " ", int $type = 1): string
{
    $sl = \strlen($s);
    if ($sl >= $len || \strlen($pad) === 0) { return $s; }
    $need = $len - $sl;
    $full = "";
    while (\strlen($full) < $need) { $full = $full . $pad; }
    if ($type === 0) { return \substr($full, 0, $need) . $s; }
    if ($type === 2) {
        $left = \intval($need / 2);
        return \substr($full, 0, $left) . $s . \substr($full, 0, $need - $left);
    }
    return $s . \substr($full, 0, $need);
}

/**
 * `number_format` — format `$num` with grouped thousands and a fixed number
 * of decimals (rounding half away from zero). Integer-unit arithmetic avoids
 * float-formatting drift.
 */
function number_format(float $num, int $decimals = 0, string $dec_point = ".", string $thousands_sep = ","): string
{
    $neg = $num < 0.0;
    if ($neg) { $num = -$num; }
    $factor = 1;
    $i = 0;
    while ($i < $decimals) { $factor = $factor * 10; $i = $i + 1; }
    // Smallest-unit integer count, rounded half away from zero.
    $scaled = (int)($num * (float)$factor + 0.5);
    $intPart = \intdiv($scaled, $factor);
    $frac = $scaled - $intPart * $factor;
    // Group the integer digits in threes from the right.
    $intStr = (string)$intPart;
    $n = \strlen($intStr);
    $grouped = "";
    $j = 0;
    while ($j < $n) {
        if ($j > 0 && ($n - $j) % 3 === 0) { $grouped = $grouped . $thousands_sep; }
        $grouped = $grouped . \substr($intStr, $j, 1);
        $j = $j + 1;
    }
    $result = $grouped;
    if ($decimals > 0) {
        $fracStr = (string)$frac;
        while (\strlen($fracStr) < $decimals) { $fracStr = "0" . $fracStr; }
        $result = $result . $dec_point . $fracStr;
    }
    if ($neg && $scaled !== 0) { $result = "-" . $result; }
    return $result;
}

/** Insert `<br />` (or `<br>` when not xhtml) before each newline sequence,
 *  keeping the newline itself (PHP `nl2br`). Handles \r\n, \n\r, \n, \r. */
function nl2br(string $s, bool $is_xhtml = true): string
{
    $br = $is_xhtml ? "<br />" : "<br>";
    $n = \strlen($s);
    $out = "";
    $i = 0;
    while ($i < $n) {
        $c = \substr($s, $i, 1);
        if ($c === "\r" || $c === "\n") {
            $nx = ($i + 1 < $n) ? \substr($s, $i + 1, 1) : "";
            // A two-char sequence (\r\n or \n\r) stays together after the tag.
            if (($c === "\r" && $nx === "\n") || ($c === "\n" && $nx === "\r")) {
                $out = $out . $br . $c . $nx;
                $i = $i + 2;
                continue;
            }
            $out = $out . $br . $c;
            $i = $i + 1;
            continue;
        }
        $out = $out . $c;
        $i = $i + 1;
    }
    return $out;
}

/** Number of words in `$s` (PHP `str_word_count` format 0). A word is a maximal
 *  run of alphabetic characters, optionally containing `'` and `-` (PHP's
 *  default locale word set). Only format 0 (the count) is supported. */
function str_word_count(string $s, int $format = 0): int
{
    $n = \strlen($s);
    $count = 0;
    $inWord = false;
    for ($i = 0; $i < $n; $i = $i + 1) {
        $c = \substr($s, $i, 1);
        $isAlpha = ($c >= "a" && $c <= "z") || ($c >= "A" && $c <= "Z");
        $isInner = $isAlpha || $c === "'" || $c === "-";
        // A run STARTS on an alphabetic char; `'`/`-` only continue an open run.
        if ($isAlpha && !$inWord) { $count = $count + 1; $inWord = true; }
        elseif (!$isInner) { $inWord = false; }
    }
    return $count;
}
