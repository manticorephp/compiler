<?php

/**
 * Civil calendar core — proleptic Gregorian, pure integer math, no tables and
 * no host dependency. Everything else in the date/time area sits on this.
 *
 * Two rules hold this file together:
 *
 * 1. FLOOR division everywhere. PHP's `intdiv` truncates toward zero, so
 *    `intdiv(-1, 400)` is 0 where the calendar needs -1. A truncating divide
 *    produces off-by-one dates for pre-1970 instants ONLY, which is exactly the
 *    kind of bug a shallow test suite never sees. Use `__mc_fdiv`/`__mc_fmod`.
 *
 * 2. Multi-value results are PACKED INTS, never arrays. A `list<int>` return
 *    costs a heap allocation in the hottest loop of the whole area, and an
 *    array crossing the stdlib call boundary is the documented fault class.
 *    `__mc_civil_from_days` returns ($y + 400000) * 512 + $m * 32 + $d: the
 *    bias keeps every intermediate positive (so the decode side cannot hit the
 *    truncating-intdiv trap either) and the whole value stays far below 2^53.
 *
 * The days<->civil pair is Howard Hinnant's era algorithm: shift the year to
 * start in March so the leap day lands last and the month-length pattern
 * becomes the closed form (153*m + 2)/5. Branch-free, correct for negative
 * years, no lookup table.
 */

/** Year bias in a packed civil value. Also the low bound of the usable range. */
function __mc_civ_bias(): int
{
    return 400000;
}

/** Floor division — `intdiv` truncates toward zero, the calendar needs floor. */
function __mc_fdiv(int $a, int $b): int
{
    $q = \intdiv($a, $b);
    if (($a % $b !== 0) && (($a < 0) !== ($b < 0))) {
        return $q - 1;
    }
    return $q;
}

/** Floor modulo — result carries the sign of the DIVISOR, as the calendar needs. */
function __mc_fmod(int $a, int $b): int
{
    $r = $a % $b;
    if ($r !== 0 && (($r < 0) !== ($b < 0))) {
        return $r + $b;
    }
    return $r;
}

/** Days since 1970-01-01 for a proleptic-Gregorian y/m/d. Hinnant's era shift. */
function __mc_days_from_civil(int $y, int $m, int $d): int
{
    // March-based year: Jan/Feb belong to the PREVIOUS year, so the leap day is
    // the last day of the year and the month-length pattern closes.
    $yy = $y;
    if ($m <= 2) {
        $yy = $yy - 1;
    }
    $era = \__mc_fdiv($yy, 400);
    $yoe = $yy - $era * 400;                       // [0, 399]
    $mp = $m + ($m > 2 ? -3 : 9);                  // March = 0 ... February = 11
    $doy = \intdiv(153 * $mp + 2, 5) + $d - 1;     // [0, 365]
    $doe = $yoe * 365 + \intdiv($yoe, 4) - \intdiv($yoe, 100) + $doy;
    return $era * 146097 + $doe - 719468;          // 719468 shifts 0000-03-01 -> 1970-01-01
}

/**
 * Inverse of __mc_days_from_civil. Returns a PACKED civil value — read it with
 * __mc_civ_y / __mc_civ_m / __mc_civ_d.
 */
function __mc_civil_from_days(int $z): int
{
    $zz = $z + 719468;
    $era = \__mc_fdiv($zz, 146097);
    $doe = $zz - $era * 146097;                    // [0, 146096]
    $yoe = \intdiv($doe - \intdiv($doe, 1460) + \intdiv($doe, 36524) - \intdiv($doe, 146096), 365);
    $yy = $yoe + $era * 400;
    $doy = $doe - (365 * $yoe + \intdiv($yoe, 4) - \intdiv($yoe, 100));
    $mp = \intdiv(5 * $doy + 2, 153);              // [0, 11], March-based
    $d = $doy - \intdiv(153 * $mp + 2, 5) + 1;     // [1, 31]
    $m = $mp + ($mp < 10 ? 3 : -9);                // [1, 12]
    $y = $yy + ($m <= 2 ? 1 : 0);
    return ($y + 400000) * 512 + $m * 32 + $d;
}

/** Year out of a packed civil value. */
function __mc_civ_y(int $packed): int
{
    return \intdiv($packed, 512) - 400000;
}

/** Month [1,12] out of a packed civil value. */
function __mc_civ_m(int $packed): int
{
    return \intdiv($packed % 512, 32);
}

/** Day [1,31] out of a packed civil value. */
function __mc_civ_d(int $packed): int
{
    return $packed % 32;
}

/** Proleptic-Gregorian leap year. */
function __mc_is_leap(int $y): bool
{
    if ($y % 4 !== 0) {
        return false;
    }
    if ($y % 100 !== 0) {
        return true;
    }
    return $y % 400 === 0;
}

/** Length of a month, February following the leap rule. */
function __mc_days_in_month(int $y, int $m): int
{
    if ($m === 2) {
        return \__mc_is_leap($y) ? 29 : 28;
    }
    if ($m === 4 || $m === 6 || $m === 9 || $m === 11) {
        return 30;
    }
    return 31;
}

/** Day of week from a day number, 0 = Sunday (1970-01-01 was a Thursday). */
function __mc_dow(int $z): int
{
    return \__mc_fmod($z + 4, 7);
}

/** Day of year, 1-based. */
function __mc_doy(int $y, int $m, int $d): int
{
    return \__mc_days_from_civil($y, $m, $d) - \__mc_days_from_civil($y, 1, 1) + 1;
}

/**
 * ISO-8601 week number and week-numbering year, PACKED as $isoYear * 64 + $isoWeek.
 *
 * The ISO week owning a date is the one containing its Thursday, so the whole
 * rule reduces to: walk to that Thursday, and the week is its day-of-year
 * divided by 7. The week-numbering year is that Thursday's calendar year, which
 * is why `o` and `Y` disagree at a year boundary (2021-01-01 is 2020-W53).
 */
function __mc_iso_week(int $y, int $m, int $d): int
{
    $z = \__mc_days_from_civil($y, $m, $d);
    $isoDow = \__mc_dow($z);
    if ($isoDow === 0) {
        $isoDow = 7;                               // ISO counts Monday=1 .. Sunday=7
    }
    $thursday = $z + (4 - $isoDow);
    $tp = \__mc_civil_from_days($thursday);
    $isoYear = \__mc_civ_y($tp);
    $week = \intdiv($thursday - \__mc_days_from_civil($isoYear, 1, 1), 7) + 1;
    return $isoYear * 64 + $week;
}

/** Week-numbering year out of a packed ISO week value. */
function __mc_iso_y(int $packed): int
{
    return \intdiv($packed, 64);
}

/** Week number [1,53] out of a packed ISO week value. */
function __mc_iso_w(int $packed): int
{
    return $packed % 64;
}

/**
 * Normalize an out-of-range civil date (month 13, day 0, day 32, ...) the way
 * PHP's timelib does: carry the month into the year first, then let a day that
 * overflows its month spill forward. `$which` selects the field to read back —
 * 0 year, 1 month, 2 day — because the result is three values and this file
 * does not return arrays.
 *
 * The month-before-day order is load-bearing: it is what makes
 * "Jan 31 + 1 month" land on March 3 rather than February 28.
 */
function __mc_norm_ymd(int $y, int $m, int $d, int $which): int
{
    // Month carry, on a 0-based month so floor division does the work.
    $m0 = $m - 1;
    $y = $y + \__mc_fdiv($m0, 12);
    $m = \__mc_fmod($m0, 12) + 1;
    if ($which === 0 && $d >= 1 && $d <= \__mc_days_in_month($y, $m)) {
        return $y;
    }
    if ($which === 1 && $d >= 1 && $d <= \__mc_days_in_month($y, $m)) {
        return $m;
    }
    if ($which === 2 && $d >= 1 && $d <= \__mc_days_in_month($y, $m)) {
        return $d;
    }
    // Day out of range: go through the day number, which normalizes by
    // construction and handles any magnitude of overflow in one step.
    $p = \__mc_civil_from_days(\__mc_days_from_civil($y, $m, 1) + ($d - 1));
    if ($which === 0) {
        return \__mc_civ_y($p);
    }
    if ($which === 1) {
        return \__mc_civ_m($p);
    }
    return \__mc_civ_d($p);
}
