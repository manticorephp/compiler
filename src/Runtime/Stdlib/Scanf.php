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
    // php returns exactly ONE entry per non-suppressed specifier whenever it
    // returns an array at all — `sscanf("x", "%d %d %d")` is [null,null,null],
    // and a LITERAL mismatch (`sscanf("nope", "age: %d")` → [null]) still gets
    // its slot even though the loop broke before reaching the conversion. The
    // arms above only append on a conversion they actually attempted, so the
    // shortfall is made up here. The `return null` above is the other rule and
    // stays: input exhausted before anything matched answers null outright.
    $slots = \__mc_scanf_slots($fmt);
    while (\count($res) < $slots) { $res[] = null; }
    return $res;
}

/**
 * How many values `$fmt` produces: `%` conversions that are neither `%%` nor
 * suppressed with `*`. Width digits and a `%[…]` set are skipped so a ']' or a
 * digit inside them is never read as a conversion character.
 */
function __mc_scanf_slots(string $fmt): int
{
    $n = 0;
    $fn = \strlen($fmt);
    $i = 0;
    while ($i < $fn) {
        if ($fmt[$i] !== '%') { $i = $i + 1; continue; }
        $i = $i + 1;
        if ($i >= $fn) { break; }
        if ($fmt[$i] === '%') { $i = $i + 1; continue; }
        $suppress = false;
        if ($fmt[$i] === '*') { $suppress = true; $i = $i + 1; }
        while ($i < $fn && $fmt[$i] >= '0' && $fmt[$i] <= '9') { $i = $i + 1; }
        if ($i >= $fn) { break; }
        if ($fmt[$i] === '[') {
            $i = $i + 1;
            if ($i < $fn && $fmt[$i] === '^') { $i = $i + 1; }
            if ($i < $fn && $fmt[$i] === ']') { $i = $i + 1; }
            while ($i < $fn && $fmt[$i] !== ']') { $i = $i + 1; }
            if ($i < $fn) { $i = $i + 1; }
        } else {
            $i = $i + 1;
        }
        if (!$suppress) { $n = $n + 1; }
    }
    return $n;
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

// ── the by-reference form ──────────────────────────────────────────────
//
// php spells it `sscanf($s, $f, &...$vars)`, and a by-ref VARIADIC pack does not
// exist here: the caller packs trailing arguments into one array literal, so the
// pack is a VALUE and the callee's writes land in a throwaway alloca. (Measured,
// not assumed — a `mixed &...$v` function compiles, reports the right count, and
// writes nothing back.) `array_multisort` is blocked on the same hole, and Zend
// special-cases that one in the engine for the same reason.
//
// So sscanf keeps its two-parameter declaration and the trailing lvalues are
// desugared at the CALL SITE, where they are still real lvalues
// ({@see \Compile\Mir\Passes\LowerExprs}) — the array_multisort treatment.
//
// ⚠ The two results are deliberately NOT flattened into one array. A first
// attempt returned `[$ret, $v0, $v1, …]`, and every value came back a denormal:
// the `$ret` slot is a raw int while the value slots hold cells read out of
// __mc_sscanf's result, so one array carried two representations and whichever
// one the reader assumed was wrong for the other half. Two separately typed
// results cost a second parse of a short string and cannot mix reprs.
//
// ⛔ Known gaps, deliberate: php counts a SUPPRESSED conversion toward the
// return (`sscanf("1 2", "%*d %d", $a)` is 2 with one value assigned); we count
// assigned values only, so that shape answers 1. No `%*` exists anywhere in the
// pinned corpus. php also raises ValueError when there are more variables than
// specifiers; we assign null instead. And `fscanf`'s by-ref form has no desugar
// at all — it would have to read the stream exactly once across both results,
// which this two-call shape cannot express (finding: fscanf-byref-form-absent).

/**
 * The values of the by-ref form, ALWAYS an array — where {@see __mc_sscanf}
 * answers null (an empty or all-whitespace subject) php still leaves every
 * variable NULL, and an index into a null is not that.
 *
 * @return array<int,mixed>
 */
function __mc_scanf_vals(string $string, string $format)
{
    $r = \__mc_sscanf($string, $format);
    if ($r !== null) {
        return $r;
    }
    $out = [];
    $n = \__mc_scanf_slots($format);
    $i = 0;
    while ($i < $n) { $out[] = null; $i = $i + 1; }
    return $out;
}

/**
 * php's own int return for the by-ref form: the number of values assigned, or
 * -1 when the subject never matched at all. NOT the specifier count — a
 * conversion that failed stops the scan and every slot after it is null.
 */
function __mc_scanf_ret(string $string, string $format): int
{
    $r = \__mc_sscanf($string, $format);
    if ($r === null) {
        return -1;
    }
    $n = 0;
    foreach ($r as $v) {
        if ($v === null) { break; }
        $n = $n + 1;
    }
    return $n;
}
