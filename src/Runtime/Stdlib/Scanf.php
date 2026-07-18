<?php

/**
 * sscanf / fscanf — the REVERSE of the printf engine ({@see \__mc_format}):
 * parse an input string against a printf-style format and return the extracted
 * values. Array-return form only (`$r = sscanf($s, $fmt)`); the by-ref form
 * (`sscanf($s, $fmt, $a, $b)`) needs variadic by-ref params, not modelled here.
 *
 * A whitespace run in the format matches any whitespace run in the input; a
 * literal byte must match (a mismatch stops the scan); `%d/%x/%o/%f/%s` skip
 * leading whitespace first, `%c`/`%[…]` do not. A failed conversion contributes
 * one NULL and stops. Verified against php 8.5 sscanf.
 */

/** Whether $c is an ASCII whitespace byte. */
function __mc_scan_ws(string $c): bool
{
    return $c === ' ' || $c === "\t" || $c === "\n" || $c === "\r" || $c === "\v" || $c === "\f";
}

/**
 * Parse $str per $fmt, returning the extracted values (or null on empty input).
 * @return array<int,mixed>|null
 */
function __mc_sscanf(string $str, string $fmt)
{
    if ($str === '') {
        return null;
    }
    $res = [];
    $sn = \strlen($str);
    $fn = \strlen($fmt);
    $si = 0;
    $fi = 0;
    $failed = false;
    while ($fi < $fn) {
        $fc = $fmt[$fi];
        if (\__mc_scan_ws($fc)) {
            // a whitespace run matches any whitespace run (possibly empty)
            $fi = $fi + 1;
            while ($si < $sn && \__mc_scan_ws($str[$si])) {
                $si = $si + 1;
            }
            continue;
        }
        if ($fc !== '%') {
            if ($si < $sn && $str[$si] === $fc) {
                $si = $si + 1;
                $fi = $fi + 1;
                continue;
            }
            break; // literal mismatch — stop
        }
        // conversion: %[*][width][conv]
        $fi = $fi + 1;
        if ($fi < $fn && $fmt[$fi] === '%') {
            if ($si < $sn && $str[$si] === '%') { $si = $si + 1; $fi = $fi + 1; continue; }
            break;
        }
        $suppress = false;
        if ($fi < $fn && $fmt[$fi] === '*') { $suppress = true; $fi = $fi + 1; }
        $width = 0;
        $hasWidth = false;
        while ($fi < $fn && $fmt[$fi] >= '0' && $fmt[$fi] <= '9') {
            $width = $width * 10 + (\ord($fmt[$fi]) - 48);
            $hasWidth = true;
            $fi = $fi + 1;
        }
        if ($fi >= $fn) {
            break;
        }
        $conv = $fmt[$fi];
        $fi = $fi + 1;
        // Once a conversion has failed the input is stuck: php fills every
        // remaining conversion with NULL (a LITERAL mismatch, handled above,
        // stops instead).
        if ($failed) { if (!$suppress) { $res[] = null; } continue; }

        // char class %[...]  (and negated %[^...])
        if ($conv === '[') {
            $neg = false;
            if ($fi < $fn && $fmt[$fi] === '^') { $neg = true; $fi = $fi + 1; }
            $set = '';
            // a leading ']' is a literal member
            if ($fi < $fn && $fmt[$fi] === ']') { $set .= ']'; $fi = $fi + 1; }
            while ($fi < $fn && $fmt[$fi] !== ']') { $set .= $fmt[$fi]; $fi = $fi + 1; }
            if ($fi < $fn) { $fi = $fi + 1; } // consume ']'
            $out = '';
            while ($si < $sn && (!$hasWidth || \strlen($out) < $width)) {
                $inSet = \__mc_scan_in_class($str[$si], $set);
                if ($neg) { $inSet = !$inSet; }
                if (!$inSet) { break; }
                $out .= $str[$si];
                $si = $si + 1;
            }
            if ($out === '') { $failed = true; if (!$suppress) { $res[] = null; } continue; }
            if (!$suppress) { $res[] = $out; }
            continue;
        }

        // %c — exactly $width chars (default 1), NO leading-ws skip
        if ($conv === 'c') {
            $take = $hasWidth ? $width : 1;
            if ($si >= $sn) { $failed = true; if (!$suppress) { $res[] = null; } continue; }
            $out = '';
            while ($si < $sn && \strlen($out) < $take) { $out .= $str[$si]; $si = $si + 1; }
            if (!$suppress) { $res[] = $out; }
            continue;
        }

        // the rest skip leading whitespace
        while ($si < $sn && \__mc_scan_ws($str[$si])) { $si = $si + 1; }
        // Input exhausted at a conversion: php returns null outright when nothing
        // has matched yet (`sscanf("  ", "%d")`), else a trailing NULL and stops.
        if ($si >= $sn) {
            if (\count($res) === 0) { return null; }
            $failed = true;
            if (!$suppress) { $res[] = null; }
            continue;
        }

        if ($conv === 's') {
            $out = '';
            while ($si < $sn && !\__mc_scan_ws($str[$si]) && (!$hasWidth || \strlen($out) < $width)) {
                $out .= $str[$si];
                $si = $si + 1;
            }
            if ($out === '') { $failed = true; if (!$suppress) { $res[] = null; } continue; }
            if (!$suppress) { $res[] = $out; }
            continue;
        }

        $start = $si;
        if ($conv === 'd' || $conv === 'i' || $conv === 'u') {
            $tok = \__mc_scan_int($str, $si, $sn, $hasWidth, $width, 10);
            $si = (int)$tok[1];
            if ($si === $start) { $failed = true; if (!$suppress) { $res[] = null; } continue; }
            if (!$suppress) { $res[] = (int)$tok[0]; }
        } elseif ($conv === 'x' || $conv === 'X') {
            $tok = \__mc_scan_int($str, $si, $sn, $hasWidth, $width, 16);
            $si = (int)$tok[1];
            if ($si === $start) { $failed = true; if (!$suppress) { $res[] = null; } continue; }
            if (!$suppress) { $res[] = \intval((string)$tok[0], 16); }
        } elseif ($conv === 'o') {
            $tok = \__mc_scan_int($str, $si, $sn, $hasWidth, $width, 8);
            $si = (int)$tok[1];
            if ($si === $start) { $failed = true; if (!$suppress) { $res[] = null; } continue; }
            if (!$suppress) { $res[] = \intval((string)$tok[0], 8); }
        } elseif ($conv === 'f' || $conv === 'e' || $conv === 'E' || $conv === 'g' || $conv === 'G') {
            $tok = \__mc_scan_float($str, $si, $sn, $hasWidth, $width);
            $si = (int)$tok[1];
            if ($si === $start) { $failed = true; if (!$suppress) { $res[] = null; } continue; }
            if (!$suppress) { $res[] = (float)$tok[0]; }
        } else {
            break; // unknown conversion
        }
    }
    return $res;
}

/** Whether $c is in the char-class $set (supports `a-z` ranges). */
function __mc_scan_in_class(string $c, string $set): bool
{
    $n = \strlen($set);
    $i = 0;
    while ($i < $n) {
        if ($i + 2 < $n && $set[$i + 1] === '-') {
            if ($c >= $set[$i] && $c <= $set[$i + 2]) { return true; }
            $i = $i + 3;
            continue;
        }
        if ($c === $set[$i]) { return true; }
        $i = $i + 1;
    }
    return false;
}

/**
 * Scan an integer token (optional sign + base digits) from $str at $i.
 * @return array{0:string,1:int} the token text and the new index
 */
function __mc_scan_int(string $str, int $i, int $n, bool $hasWidth, int $width, int $base): array
{
    $out = '';
    if ($i < $n && ($str[$i] === '-' || $str[$i] === '+')) {
        $out .= $str[$i];
        $i = $i + 1;
    }
    while ($i < $n && (!$hasWidth || \strlen($out) < $width)) {
        $c = $str[$i];
        $ok = ($c >= '0' && $c <= '9');
        if ($base === 16) {
            $ok = $ok || ($c >= 'a' && $c <= 'f') || ($c >= 'A' && $c <= 'F');
        }
        if ($base === 8) {
            $ok = ($c >= '0' && $c <= '7');
        }
        if (!$ok) { break; }
        $out .= $c;
        $i = $i + 1;
    }
    // a lone sign is not a number
    if ($out === '-' || $out === '+' || $out === '') { return ['', $i - \strlen($out)]; }
    return [$out, $i];
}

/**
 * Scan a float token from $str at $i.
 * @return array{0:string,1:int}
 */
function __mc_scan_float(string $str, int $i, int $n, bool $hasWidth, int $width): array
{
    $out = '';
    $start = $i;
    if ($i < $n && ($str[$i] === '-' || $str[$i] === '+')) { $out .= $str[$i]; $i = $i + 1; }
    $digits = false;
    while ($i < $n && $str[$i] >= '0' && $str[$i] <= '9') { $out .= $str[$i]; $i = $i + 1; $digits = true; }
    if ($i < $n && $str[$i] === '.') {
        $out .= '.'; $i = $i + 1;
        while ($i < $n && $str[$i] >= '0' && $str[$i] <= '9') { $out .= $str[$i]; $i = $i + 1; $digits = true; }
    }
    if (!$digits) { return ['', $start]; }
    if ($i < $n && ($str[$i] === 'e' || $str[$i] === 'E')) {
        $save = $i;
        $exp = $str[$i]; $i = $i + 1;
        if ($i < $n && ($str[$i] === '-' || $str[$i] === '+')) { $exp .= $str[$i]; $i = $i + 1; }
        $ed = false;
        while ($i < $n && $str[$i] >= '0' && $str[$i] <= '9') { $exp .= $str[$i]; $i = $i + 1; $ed = true; }
        if ($ed) { $out .= $exp; } else { $i = $save; }
    }
    return [$out, $i];
}

/**
 * Parse $string per $format. Array-return form.
 * @return array<int,mixed>|null
 */
function sscanf(string $string, string $format)
{
    return \__mc_sscanf($string, $format);
}

/**
 * Read one line from $stream and parse it per $format.
 * @return array<int,mixed>|false|null
 */
function fscanf(\Resource $stream, string $format)
{
    $line = \fgets($stream);
    if ($line === false) {
        return false;
    }
    return \__mc_sscanf((string)$line, $format);
}
