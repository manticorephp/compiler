<?php

/**
 * mbstring case mapping and display width — a transcription of php_unicode.c's
 * php_unicode_convert_case and mbstring.c's width functions over tables read
 * out of Zend (MbstringTables.php). Zend's mbstring does NOT use ICU for case:
 * its own tables are the oracle, special casing (ß → SS, ŉ → ʼN, …), the Greek
 * final-sigma rule and the ISO-8859-9 dotted/dotless i included.
 *
 * Modes are the MB_CASE_* values: 0 UPPER, 1 LOWER, 2 TITLE, 3 FOLD, 4 UPPER_SIMPLE,
 * 5 LOWER_SIMPLE, 6 TITLE_SIMPLE, 7 FOLD_SIMPLE. Codepoints travel as C wchars:
 * 0xFFFFFFFF is the malformed-input marker, anything past 0xFFFFFF passes untouched.
 */

/** Whether `$cp` falls in a [first, last] range list. @param int[] $r */
function __mc_mb_in_ranges(array $r, int $cp): bool
{
    $lo = 0;
    $hi = \intdiv(\count($r), 2);
    while ($lo < $hi) {
        $mid = \intdiv($lo + $hi, 2);
        if ($cp < $r[$mid * 2]) {
            $hi = $mid;
        } elseif ($cp > $r[$mid * 2 + 1]) {
            $lo = $mid + 1;
        } else {
            return true;
        }
    }
    return false;
}

function __mc_mb_is_ignorable(int $cp): bool
{
    static $r = [];
    if ($r === []) { $r = \__mc_mb_case_ignorable(); }
    return \__mc_mb_in_ranges($r, $cp);
}

/** Zend's php_unicode_is_cased — true for the cased case-ignorables too. */
function __mc_mb_is_cased(int $cp): bool
{
    static $r = [];
    static $ri = [];
    if ($r === []) {
        $r = \__mc_mb_case_cased();
        $ri = \__mc_mb_case_cased_ignorable();
    }
    return \__mc_mb_in_ranges($r, $cp) || \__mc_mb_in_ranges($ri, $cp);
}

/** @return array<int,string> full mapping table of mode 0..3 */
function __mc_mb_case_full(int $m): array
{
    static $t = [];
    if (!isset($t[$m])) {
        $t[$m] = $m === 0 ? \__mc_mb_case_upper() : ($m === 1 ? \__mc_mb_case_lower() : ($m === 2 ? \__mc_mb_case_title() : \__mc_mb_case_fold()));
    }
    return $t[$m];
}

/** @return array<int,int> simple-mapping exceptions of mode 0..3 */
function __mc_mb_case_simple_fix(int $m): array
{
    static $t = [];
    if (!isset($t[$m])) {
        $t[$m] = $m === 0 ? \__mc_mb_case_upper_simple() : ($m === 1 ? \__mc_mb_case_lower_simple()
            : ($m === 2 ? \__mc_mb_case_title_simple() : \__mc_mb_case_fold_simple()));
    }
    return $t[$m];
}

/**
 * Full mapping of `$cp` under base mode `$m` (0 upper, 1 lower, 2 title, 3 fold)
 * as codepoints, the ISO-8859-9 i/I rules applied.
 * @return int[]
 */
function __mc_mb_map_full(int $m, int $cp, bool $turkish): array
{
    if ($turkish) {
        if (($m === 0 || $m === 2) && $cp === 0x69) { return [0x130]; }
        if (($m === 1 || $m === 3) && $cp === 0x49) { return [0x131]; }
        if (($m === 1 || $m === 3) && $cp === 0x130) { return [0x69]; }
    }
    $t = \__mc_mb_case_full($m);
    if (!isset($t[$cp])) { return [$cp]; }
    return \__mc_mb_units($t[$cp]);
}

/** Simple mapping of `$cp` under base mode `$m`: always one codepoint. */
function __mc_mb_map_simple(int $m, int $cp, bool $turkish): int
{
    $fix = \__mc_mb_case_simple_fix($m);
    if (isset($fix[$cp]) && !($turkish && ($cp === 0x69 || $cp === 0x49 || $cp === 0x130))) { return $fix[$cp]; }
    $full = \__mc_mb_map_full($m, $cp, $turkish);
    return \count($full) === 1 ? $full[0] : $cp;
}

/**
 * Case-convert C wchars. Zend decodes in 64-codepoint buffers and the final-sigma
 * rule looks back only through the current buffer — then through what is left
 * of the previous buffer's OUTPUT past this one's write position — and looks
 * ahead into the rest of the input; both reproduced.
 * @param int[] $w
 * @return int[]
 */
function __mc_mb_case_wchars(int $mode, array $w, bool $turkish): array
{
    $out = [];
    $n = \count($w);
    $title = false;
    $prev = [];
    $base = 0;
    while ($base < $n) {
        $len = \min(64, $n - $base);
        $conv = [];
        $i = 0;
        while ($i < $len) {
            $c = $w[$base + $i];
            if ($c > 0xFFFFFF) {
                $conv[] = $c;
                $i = $i + 1;
                continue;
            }
            if ($mode >= 4) {
                $m = $mode - 4;
                if ($m === 2) {
                    $conv[] = $title ? \__mc_mb_map_simple(1, $c, $turkish) : \__mc_mb_map_simple(2, $c, $turkish);
                    if (!\__mc_mb_is_ignorable($c)) { $title = \__mc_mb_is_cased($c); }
                } else {
                    $conv[] = \__mc_mb_map_simple($m, $c, $turkish);
                }
                $i = $i + 1;
                continue;
            }
            $m = $mode;
            if ($m === 2 && $title) { $m = 1; }
            if ($m === 1 && $c === 0x3A3 && \__mc_mb_final_sigma($w, $base, $len, $i, $conv, $prev)) {
                $conv[] = 0x3C2;
            } else {
                foreach (\__mc_mb_map_full($m, $c, $turkish) as $x) { $conv[] = $x; }
            }
            if ($mode === 2 && !\__mc_mb_is_ignorable($c)) { $title = \__mc_mb_is_cased($c); }
            $i = $i + 1;
        }
        foreach ($conv as $x) { $out[] = $x; }
        $prev = $conv;
        $base = $base + $len;
    }
    return $out;
}

/**
 * The final-sigma test for the capital sigma at index `$i` of the buffer that
 * starts at `$base`: a cased letter before it (through case-ignorables) and
 * none after it.
 * @param int[] $w
 * @param int[] $conv
 * @param int[] $prev
 */
function __mc_mb_final_sigma(array $w, int $base, int $len, int $i, array $conv, array $prev): bool
{
    $j = $i - 1;
    while ($j >= 0 && \__mc_mb_is_ignorable($w[$base + $j])) { $j = $j - 1; }
    if ($j >= 0) {
        if (!\__mc_mb_is_cased($w[$base + $j])) { return false; }
    } else {
        $found = false;
        $k = \count($prev) - 1;
        $stop = \count($conv);
        while ($k >= $stop) {
            if (\__mc_mb_is_cased($prev[$k])) {
                $found = true;
                break;
            }
            if (!\__mc_mb_is_ignorable($prev[$k])) { break; }
            $k = $k - 1;
        }
        if (!$found) { return false; }
    }
    $j = $i + 1;
    while ($j < $len && \__mc_mb_is_ignorable($w[$base + $j])) { $j = $j + 1; }
    if ($j < $len) { return !\__mc_mb_is_cased($w[$base + $j]); }
    $n = \count($w);
    $k = $base + $len;
    while ($k < $n) {
        if (\__mc_mb_is_cased($w[$k])) { return false; }
        if (!\__mc_mb_is_ignorable($w[$k])) { return true; }
        $k = $k + 1;
    }
    return true;
}

/** mbstring_convert_case: `$s` in `$enc`, case-converted, back in `$enc`. */
function __mc_mb_convert_case(int $mode, string $s, string $enc): string
{
    if ($s === "") { return ""; }
    $w = \__mc_mb_raw_units(\__mc_mb_dec8($enc, $s, true));
    return (string)\__mc_mb_enc8($enc, \__mc_mb_wchars8(\__mc_mb_case_wchars($mode, $w, $enc === "ISO-8859-9")));
}

function mb_convert_case(string $string, int $mode, ?string $encoding = null): string
{
    $enc = \__mc_mb_enc($encoding, "mb_convert_case", 3);
    if ($mode < 0 || $mode > 7) {
        throw new \ValueError("mb_convert_case(): Argument #2 (\$mode) must be one of the MB_CASE_* constants");
    }
    return \__mc_mb_convert_case($mode, $string, $enc);
}

function mb_strtoupper(string $string, ?string $encoding = null): string
{
    return \__mc_mb_convert_case(0, $string, \__mc_mb_enc($encoding, "mb_strtoupper", 2));
}

function mb_strtolower(string $string, ?string $encoding = null): string
{
    return \__mc_mb_convert_case(1, $string, \__mc_mb_enc($encoding, "mb_strtolower", 2));
}

/** mb_ucfirst / mb_lcfirst: case-convert the first character, keep the rest as it is. */
function __mc_mb_first(string $fn, int $mode, string $string, ?string $encoding): string
{
    $enc = \__mc_mb_enc($encoding, $fn, 2);
    $kind = \__mc_mb_kind_of($enc);
    $first = \__mc_mb_substr($enc, $kind, $string, 0, 1);
    $head = \__mc_mb_convert_case($mode, $first, $enc);
    if ($head === $first) { return $string; }
    return $head . \__mc_mb_substr($enc, $kind, $string, 1, null);
}

function mb_ucfirst(string $string, ?string $encoding = null): string
{
    return \__mc_mb_first("mb_ucfirst", 2, $string, $encoding);
}

function mb_lcfirst(string $string, ?string $encoding = null): string
{
    return \__mc_mb_first("mb_lcfirst", 1, $string, $encoding);
}

/**
 * Zend's php_mb_stripos: both strings simply case-folded into (marked) UTF-8,
 * then the ordinary UTF-8 search. -1 not found, -2 offset out of range.
 */
function __mc_mb_ipos(string $enc, string $haystack, string $needle, int $offset, bool $reverse): int
{
    $turkish = $enc === "ISO-8859-9";
    $h = \__mc_mb_wchars8(\__mc_mb_case_wchars(7, \__mc_mb_raw_units(\__mc_mb_dec8($enc, $haystack)), $turkish));
    $n = \__mc_mb_wchars8(\__mc_mb_case_wchars(7, \__mc_mb_raw_units(\__mc_mb_dec8($enc, $needle)), $turkish));
    return \__mc_mb_find($h, $n, $offset, $reverse);
}

function __mc_mb_ipos_checked(string $fn, string $enc, string $haystack, string $needle, int $offset, bool $reverse): int
{
    $at = \__mc_mb_ipos($enc, $haystack, $needle, $offset, $reverse);
    if ($at === -2) {
        throw new \ValueError($fn . "(): Argument #3 (\$offset) must be contained in argument #1 (\$haystack)");
    }
    return $at;
}

function mb_stripos(string $haystack, string $needle, int $offset = 0, ?string $encoding = null): int|false
{
    $at = \__mc_mb_ipos_checked("mb_stripos", \__mc_mb_enc($encoding, "mb_stripos", 4), $haystack, $needle, $offset, false);
    return $at < 0 ? false : $at;
}

function mb_strripos(string $haystack, string $needle, int $offset = 0, ?string $encoding = null): int|false
{
    $at = \__mc_mb_ipos_checked("mb_strripos", \__mc_mb_enc($encoding, "mb_strripos", 4), $haystack, $needle, $offset, true);
    return $at < 0 ? false : $at;
}

function mb_stristr(string $haystack, string $needle, bool $before_needle = false, ?string $encoding = null): string|false
{
    $enc = \__mc_mb_enc($encoding, "mb_stristr", 4);
    $at = \__mc_mb_ipos_checked("mb_stristr", $enc, $haystack, $needle, 0, false);
    if ($at < 0) { return false; }
    $kind = \__mc_mb_kind_of($enc);
    return $before_needle ? \__mc_mb_substr($enc, $kind, $haystack, 0, $at) : \__mc_mb_substr($enc, $kind, $haystack, $at, null);
}

function mb_strrichr(string $haystack, string $needle, bool $before_needle = false, ?string $encoding = null): string|false
{
    $enc = \__mc_mb_enc($encoding, "mb_strrichr", 4);
    $at = \__mc_mb_ipos_checked("mb_strrichr", $enc, $haystack, $needle, 0, true);
    if ($at < 0) { return false; }
    $kind = \__mc_mb_kind_of($enc);
    return $before_needle ? \__mc_mb_substr($enc, $kind, $haystack, 0, $at) : \__mc_mb_substr($enc, $kind, $haystack, $at, null);
}

/** Display width of one C wchar: 2 in the East Asian wide ranges, else 1. */
function __mc_mb_cwidth(int $w): int
{
    static $r = [];
    if ($r === []) { $r = \__mc_mb_wide(); }
    if ($w < 0x1100 || $w > 0x10FFFF) { return 1; }
    return \__mc_mb_in_ranges($r, $w) ? 2 : 1;
}

/** @param int[] $w */
function __mc_mb_width_of(array $w): int
{
    $t = 0;
    foreach ($w as $c) { $t = $t + \__mc_mb_cwidth($c); }
    return $t;
}

function mb_strwidth(string $string, ?string $encoding = null): int
{
    $enc = \__mc_mb_enc($encoding, "mb_strwidth", 2);
    return \__mc_mb_width_of(\__mc_mb_raw_units(\__mc_mb_dec8($enc, $string, true)));
}

function mb_strimwidth(string $string, int $start, int $width, string $trim_marker = "", ?string $encoding = null): string
{
    $enc = \__mc_mb_enc($encoding, "mb_strimwidth", 5);
    $kind = \__mc_mb_kind_of($enc);
    $w = \__mc_mb_raw_units(\__mc_mb_dec8($enc, $string, true));
    if ($start !== 0) {
        $len = \__mc_mb_len($enc, $kind, $string);
        if ($start < 0) { $start = $start + $len; }
        if ($start < 0 || $start > $len) {
            throw new \ValueError("mb_strimwidth(): Argument #2 (\$start) is out of range");
        }
    }
    if ($width < 0) {
        $width = $width + \__mc_mb_width_of($w);
        if ($start > 0) {
            $head = \__mc_mb_substr($enc, $kind, $string, 0, $start);
            $width = $width - \__mc_mb_width_of(\__mc_mb_raw_units(\__mc_mb_dec8($enc, $head, true)));
        }
        if ($width < 0) {
            throw new \ValueError("mb_strimwidth(): Argument #3 (\$width) is out of range");
        }
    }
    $n = \count($w);
    $remaining = $width;
    $err = false;
    $i = $start;
    while ($i < $n) {
        $cw = \__mc_mb_cwidth($w[$i]);
        if ($w[$i] === 0xFFFFFFFF) { $err = true; }
        if ($remaining < $cw) {
            $markerWidth = \__mc_mb_width_of(\__mc_mb_raw_units(\__mc_mb_dec8($enc, $trim_marker, true)));
            if ($width <= $markerWidth) { return $trim_marker; }
            $keep = $width - $markerWidth;
            $k = $start;
            while ($k < $n) {
                $cw2 = \__mc_mb_cwidth($w[$k]);
                if ($keep < $cw2) { break; }
                $keep = $keep - $cw2;
                $k = $k + 1;
            }
            return (string)\__mc_mb_enc8($enc, \__mc_mb_wchars8(\array_slice($w, $start, $k - $start))) . $trim_marker;
        }
        $remaining = $remaining - $cw;
        $i = $i + 1;
    }
    if (!$err) {
        return $start === 0 ? $string : \__mc_mb_substr($enc, $kind, $string, $start, null);
    }
    return (string)\__mc_mb_enc8($enc, \__mc_mb_wchars8(\array_slice($w, $start)));
}
