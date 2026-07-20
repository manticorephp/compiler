<?php

/**
 * The `date()` specifier engine.
 *
 * One pass over the format string with the accumulator seeded to '' (never
 * null — a null-seeded accumulator is a documented mis-boxing hazard), and the
 * backslash escape handled IN THE SCANNER rather than by a pre/post replace
 * pass, which is the only way `'\\Y'` and `'Y'` can both be right.
 *
 * A moment is (int $ts UTC seconds, int $us microseconds) plus an integer zone
 * handle — never a float, never a struct.
 */

/** Two-digit zero pad. */
function __mc_d2(int $v): string
{
    if ($v < 10 && $v >= 0) {
        return '0' . $v;
    }
    return (string)$v;
}

/** Four-digit zero pad, for years. */
function __mc_d4(int $v): string
{
    if ($v < 0) {
        return '-' . \__mc_d4(-$v);
    }
    if ($v < 10) {
        return '000' . $v;
    }
    if ($v < 100) {
        return '00' . $v;
    }
    if ($v < 1000) {
        return '0' . $v;
    }
    return (string)$v;
}

/** Full weekday name, 0 = Sunday. */
function __mc_dayname(int $w): string
{
    if ($w === 0) { return 'Sunday'; }
    if ($w === 1) { return 'Monday'; }
    if ($w === 2) { return 'Tuesday'; }
    if ($w === 3) { return 'Wednesday'; }
    if ($w === 4) { return 'Thursday'; }
    if ($w === 5) { return 'Friday'; }
    return 'Saturday';
}

/** Full month name, 1 = January. */
function __mc_monthname(int $m): string
{
    if ($m === 1) { return 'January'; }
    if ($m === 2) { return 'February'; }
    if ($m === 3) { return 'March'; }
    if ($m === 4) { return 'April'; }
    if ($m === 5) { return 'May'; }
    if ($m === 6) { return 'June'; }
    if ($m === 7) { return 'July'; }
    if ($m === 8) { return 'August'; }
    if ($m === 9) { return 'September'; }
    if ($m === 10) { return 'October'; }
    if ($m === 11) { return 'November'; }
    return 'December';
}

/** English ordinal suffix. 11, 12 and 13 are 'th', not 'st'/'nd'/'rd'. */
function __mc_ordsuffix(int $d): string
{
    $t = $d % 100;
    if ($t >= 11 && $t <= 13) {
        return 'th';
    }
    $u = $d % 10;
    if ($u === 1) { return 'st'; }
    if ($u === 2) { return 'nd'; }
    if ($u === 3) { return 'rd'; }
    return 'th';
}

/** "+02:00" / "+0200" style offset rendering. */
function __mc_offstr(int $off, string $sep, bool $zForUtc): string
{
    if ($zForUtc && $off === 0) {
        return 'Z';
    }
    $sign = $off < 0 ? '-' : '+';
    $a = $off < 0 ? -$off : $off;
    return $sign . \__mc_d2(\intdiv($a, 3600)) . $sep . \__mc_d2(\intdiv($a % 3600, 60));
}

/**
 * Render $fmt for the UTC instant ($ts, $us) as seen in zone $zid.
 *
 * $zname/$ztype carry what the zone was CONSTRUCTED from, which 'e' and 'T'
 * need and a bare offset cannot supply: type 1 is a fixed offset, 2 an
 * abbreviation, 3 a tz-database identifier.
 *
 * Type 4 is gmdate()'s UTC, which is NOT the same as the 'UTC' zone: PHP
 * renders its abbreviation as 'GMT' but its identifier as 'UTC', so 'T' and
 * 'e' disagree and no other type can express that.
 */
function __mc_date_fmt(string $fmt, int $ts, int $us, int $zid, string $zname, int $ztype, int $zoff): string
{
    if ($ztype === 4) {
        $off = 0;
        $isdst = 0;
        $abbr = 'GMT';
    } elseif ($ztype === 3) {
        $off = \__mc_tz_offset($zid, $ts);
        $isdst = \__mc_tz_isdst($zid, $ts);
        $abbr = \__mc_tz_abbr($zid, $ts);
    } else {
        $off = $zoff;
        $isdst = 0;
        // A FIXED-offset zone has no abbreviation of its own, so php renders
        // 'T' as GMT+hhmm while 'e' still shows the +hh:mm identifier.
        $abbr = $ztype === 2 ? $zname : 'GMT' . \__mc_offstr($zoff, '', false);
    }

    $local = $ts + $off;
    $days = \__mc_fdiv($local, 86400);
    $sod = $local - $days * 86400;
    $p = \__mc_civil_from_days($days);
    $y = \__mc_civ_y($p);
    $mo = \__mc_civ_m($p);
    $d = \__mc_civ_d($p);
    $h = \intdiv($sod, 3600);
    $mi = \intdiv($sod % 3600, 60);
    $s = $sod % 60;
    $w = \__mc_dow($days);

    $out = '';
    $n = \strlen($fmt);
    for ($i = 0; $i < $n; $i++) {
        $c = $fmt[$i];
        if ($c === '\\') {
            // Escape: emit the next byte verbatim. Must happen here, in the
            // scanner, or an escaped specifier is indistinguishable from a real
            // one by the time a replace pass runs.
            if ($i + 1 < $n) {
                $out = $out . $fmt[$i + 1];
                $i = $i + 1;
            }
            continue;
        }
        if ($c === 'd') { $out = $out . \__mc_d2($d); continue; }
        if ($c === 'j') { $out = $out . $d; continue; }
        if ($c === 'D') { $out = $out . \substr(\__mc_dayname($w), 0, 3); continue; }
        if ($c === 'l') { $out = $out . \__mc_dayname($w); continue; }
        if ($c === 'N') { $out = $out . ($w === 0 ? 7 : $w); continue; }
        if ($c === 'w') { $out = $out . $w; continue; }
        if ($c === 'S') { $out = $out . \__mc_ordsuffix($d); continue; }
        if ($c === 'z') { $out = $out . (\__mc_doy($y, $mo, $d) - 1); continue; }
        if ($c === 'W') { $out = $out . \__mc_d2(\__mc_iso_w(\__mc_iso_week($y, $mo, $d))); continue; }
        if ($c === 'o') { $out = $out . \__mc_iso_y(\__mc_iso_week($y, $mo, $d)); continue; }
        if ($c === 'F') { $out = $out . \__mc_monthname($mo); continue; }
        if ($c === 'M') { $out = $out . \substr(\__mc_monthname($mo), 0, 3); continue; }
        if ($c === 'm') { $out = $out . \__mc_d2($mo); continue; }
        if ($c === 'n') { $out = $out . $mo; continue; }
        if ($c === 't') { $out = $out . \__mc_days_in_month($y, $mo); continue; }
        if ($c === 'L') { $out = $out . (\__mc_is_leap($y) ? '1' : '0'); continue; }
        if ($c === 'Y') { $out = $out . $y; continue; }
        if ($c === 'y') { $out = $out . \__mc_d2(\__mc_fmod($y, 100)); continue; }
        if ($c === 'X') { $out = $out . ($y >= 0 ? '+' : '-') . \__mc_d4($y < 0 ? -$y : $y); continue; }
        if ($c === 'x') { $out = $out . (($y > 9999 || $y < 0) ? (($y >= 0 ? '+' : '-') . \__mc_d4($y < 0 ? -$y : $y)) : \__mc_d4($y)); continue; }
        if ($c === 'a') { $out = $out . ($h < 12 ? 'am' : 'pm'); continue; }
        if ($c === 'A') { $out = $out . ($h < 12 ? 'AM' : 'PM'); continue; }
        if ($c === 'g') { $out = $out . ($h % 12 === 0 ? 12 : $h % 12); continue; }
        if ($c === 'G') { $out = $out . $h; continue; }
        if ($c === 'h') { $out = $out . \__mc_d2($h % 12 === 0 ? 12 : $h % 12); continue; }
        if ($c === 'H') { $out = $out . \__mc_d2($h); continue; }
        if ($c === 'i') { $out = $out . \__mc_d2($mi); continue; }
        if ($c === 's') { $out = $out . \__mc_d2($s); continue; }
        if ($c === 'u') { $out = $out . \str_pad((string)$us, 6, '0', \STR_PAD_LEFT); continue; }
        if ($c === 'v') { $out = $out . \str_pad((string)\intdiv($us, 1000), 3, '0', \STR_PAD_LEFT); continue; }
        if ($c === 'B') {
            // Swatch internet time: always Biel Mean Time (UTC+1), never local.
            $bmt = \__mc_fmod($ts + 3600, 86400);
            $out = $out . \str_pad((string)\intdiv($bmt * 10, 864), 3, '0', \STR_PAD_LEFT);
            continue;
        }
        if ($c === 'e') { $out = $out . ($ztype === 1 ? \__mc_offstr($off, ':', false) : $zname); continue; }
        if ($c === 'I') { $out = $out . $isdst; continue; }
        if ($c === 'T') { $out = $out . $abbr; continue; }
        if ($c === 'O') { $out = $out . \__mc_offstr($off, '', false); continue; }
        if ($c === 'P') { $out = $out . \__mc_offstr($off, ':', false); continue; }
        if ($c === 'p') { $out = $out . \__mc_offstr($off, ':', true); continue; }
        if ($c === 'Z') { $out = $out . $off; continue; }
        if ($c === 'U') { $out = $out . $ts; continue; }
        if ($c === 'c') {
            $out = $out . \__mc_d4($y) . '-' . \__mc_d2($mo) . '-' . \__mc_d2($d) . 'T'
                . \__mc_d2($h) . ':' . \__mc_d2($mi) . ':' . \__mc_d2($s) . \__mc_offstr($off, ':', false);
            continue;
        }
        if ($c === 'r') {
            $out = $out . \substr(\__mc_dayname($w), 0, 3) . ', ' . \__mc_d2($d) . ' '
                . \substr(\__mc_monthname($mo), 0, 3) . ' ' . $y . ' '
                . \__mc_d2($h) . ':' . \__mc_d2($mi) . ':' . \__mc_d2($s) . ' ' . \__mc_offstr($off, '', false);
            continue;
        }
        $out = $out . $c;
    }
    return $out;
}
