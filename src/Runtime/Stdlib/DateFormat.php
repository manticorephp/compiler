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
        // isdst/abbr are resolved at 'I'/'T': most formats never ask.
        $isdst = 0;
        $abbr = '';
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
        $c = \ord($fmt[$i]);
        if ($c === 92) {
            // Escape: emit the next byte verbatim. Must happen here, in the
            // scanner, or an escaped specifier is indistinguishable from a real
            // one by the time a replace pass runs.
            if ($i + 1 < $n) {
                $out = $out . $fmt[$i + 1];
                $i = $i + 1;
            }
            continue;
        }
        if ($c === 100) { $out = $out . \__mc_d2($d); continue; }
        if ($c === 106) { $out = $out . $d; continue; }
        if ($c === 68) { $out = $out . \substr(\__mc_dayname($w), 0, 3); continue; }
        if ($c === 108) { $out = $out . \__mc_dayname($w); continue; }
        if ($c === 78) { $out = $out . ($w === 0 ? 7 : $w); continue; }
        if ($c === 119) { $out = $out . $w; continue; }
        if ($c === 83) { $out = $out . \__mc_ordsuffix($d); continue; }
        if ($c === 122) { $out = $out . (\__mc_doy($y, $mo, $d) - 1); continue; }
        if ($c === 87) { $out = $out . \__mc_d2(\__mc_iso_w(\__mc_iso_week($y, $mo, $d))); continue; }
        if ($c === 111) { $out = $out . \__mc_iso_y(\__mc_iso_week($y, $mo, $d)); continue; }
        if ($c === 70) { $out = $out . \__mc_monthname($mo); continue; }
        if ($c === 77) { $out = $out . \substr(\__mc_monthname($mo), 0, 3); continue; }
        if ($c === 109) { $out = $out . \__mc_d2($mo); continue; }
        if ($c === 110) { $out = $out . $mo; continue; }
        if ($c === 116) { $out = $out . \__mc_days_in_month($y, $mo); continue; }
        if ($c === 76) { $out = $out . (\__mc_is_leap($y) ? '1' : '0'); continue; }
        if ($c === 89) { $out = $out . \__mc_d4($y); continue; }
        if ($c === 121) { $out = $out . \__mc_d2(\__mc_fmod($y, 100)); continue; }
        if ($c === 88) { $out = $out . ($y >= 0 ? '+' : '-') . \__mc_d4($y < 0 ? -$y : $y); continue; }
        if ($c === 120) { $out = $out . (($y > 9999 || $y < 0) ? (($y >= 0 ? '+' : '-') . \__mc_d4($y < 0 ? -$y : $y)) : \__mc_d4($y)); continue; }
        if ($c === 97) { $out = $out . ($h < 12 ? 'am' : 'pm'); continue; }
        if ($c === 65) { $out = $out . ($h < 12 ? 'AM' : 'PM'); continue; }
        if ($c === 103) { $out = $out . ($h % 12 === 0 ? 12 : $h % 12); continue; }
        if ($c === 71) { $out = $out . $h; continue; }
        if ($c === 104) { $out = $out . \__mc_d2($h % 12 === 0 ? 12 : $h % 12); continue; }
        if ($c === 72) { $out = $out . \__mc_d2($h); continue; }
        if ($c === 105) { $out = $out . \__mc_d2($mi); continue; }
        if ($c === 115) { $out = $out . \__mc_d2($s); continue; }
        if ($c === 117) { $out = $out . \str_pad((string)$us, 6, '0', \STR_PAD_LEFT); continue; }
        if ($c === 118) { $out = $out . \str_pad((string)\intdiv($us, 1000), 3, '0', \STR_PAD_LEFT); continue; }
        if ($c === 66) {
            // Swatch internet time: always Biel Mean Time (UTC+1), never local.
            $bmt = \__mc_fmod($ts + 3600, 86400);
            $out = $out . \str_pad((string)\intdiv($bmt * 10, 864), 3, '0', \STR_PAD_LEFT);
            continue;
        }
        if ($c === 101) { $out = $out . ($ztype === 1 ? \__mc_offstr($off, ':', false) : $zname); continue; }
        if ($c === 73) { $out = $out . ($ztype === 3 ? \__mc_tz_isdst($zid, $ts) : $isdst); continue; }
        if ($c === 84) { $out = $out . ($ztype === 3 ? \__mc_tz_abbr($zid, $ts) : $abbr); continue; }
        if ($c === 79) { $out = $out . \__mc_offstr($off, '', false); continue; }
        if ($c === 80) { $out = $out . \__mc_offstr($off, ':', false); continue; }
        if ($c === 112) { $out = $out . \__mc_offstr($off, ':', true); continue; }
        if ($c === 90) { $out = $out . $off; continue; }
        if ($c === 85) { $out = $out . $ts; continue; }
        if ($c === 99) {
            $out = $out . \__mc_d4($y) . '-' . \__mc_d2($mo) . '-' . \__mc_d2($d) . 'T'
                . \__mc_d2($h) . ':' . \__mc_d2($mi) . ':' . \__mc_d2($s) . \__mc_offstr($off, ':', false);
            continue;
        }
        if ($c === 114) {
            $out = $out . \substr(\__mc_dayname($w), 0, 3) . ', ' . \__mc_d2($d) . ' '
                . \substr(\__mc_monthname($mo), 0, 3) . ' ' . \__mc_d4($y) . ' '
                . \__mc_d2($h) . ':' . \__mc_d2($mi) . ':' . \__mc_d2($s) . ' ' . \__mc_offstr($off, '', false);
            continue;
        }
        $out = $out . $fmt[$i];
    }
    return $out;
}
