<?php

/**
 * Scalar operations behind the DateTime class family.
 *
 * Everything here takes and returns ints/strings: the classes live in the
 * prelude and cannot be named by a stdlib signature, so the boundary between
 * them is scalars only. Where a result is genuinely several values (diff), the
 * caller asks for one field at a time via a `$which` selector and assembles the
 * object on its own side.
 *
 * A zone is carried as the triple ($zid, $ztype, $zoff) rather than an object,
 * so a fixed-offset zone and a tz-database zone travel the same way.
 */

/** UTC instant -> local wall-clock seconds, for either zone flavor. */
function __mc_dt_local(int $ts, int $zid, int $ztype, int $zoff): int
{
    if ($ztype === 3) {
        return $ts + \__mc_tz_offset($zid, $ts);
    }
    return $ts + $zoff;
}

/** Local wall-clock seconds -> UTC instant, resolving gap/fold for a real zone. */
function __mc_dt_utc(int $local, int $zid, int $ztype, int $zoff): int
{
    if ($ztype === 3) {
        return \__mc_tz_localtoutc($zid, $local);
    }
    return $local - $zoff;
}

/** Replace the date part, keeping the time of day. Out-of-range values normalize. */
function __mc_dt_setdate(int $ts, int $zid, int $ztype, int $zoff, int $y, int $m, int $d): int
{
    $local = \__mc_dt_local($ts, $zid, $ztype, $zoff);
    $days = \__mc_fdiv($local, 86400);
    $tod = $local - $days * 86400;
    $ny = \__mc_norm_ymd($y, $m, $d, 0);
    $nm = \__mc_norm_ymd($y, $m, $d, 1);
    $nd = \__mc_norm_ymd($y, $m, $d, 2);
    return \__mc_dt_utc(\__mc_days_from_civil($ny, $nm, $nd) * 86400 + $tod, $zid, $ztype, $zoff);
}

/** Replace the date from an ISO year/week/day, keeping the time of day. */
function __mc_dt_setisodate(int $ts, int $zid, int $ztype, int $zoff, int $y, int $w, int $dow): int
{
    $local = \__mc_dt_local($ts, $zid, $ztype, $zoff);
    $days = \__mc_fdiv($local, 86400);
    $tod = $local - $days * 86400;
    // ISO week 1 is the one containing January 4th.
    $jan4 = \__mc_days_from_civil($y, 1, 4);
    $dow4 = \__mc_dow($jan4);
    $isoDow4 = $dow4 === 0 ? 7 : $dow4;
    $week1Mon = $jan4 - ($isoDow4 - 1);
    $z = $week1Mon + ($w - 1) * 7 + ($dow - 1);
    return \__mc_dt_utc($z * 86400 + $tod, $zid, $ztype, $zoff);
}

/** Replace the time of day, keeping the date. */
function __mc_dt_settime(int $ts, int $zid, int $ztype, int $zoff, int $h, int $mi, int $s): int
{
    $local = \__mc_dt_local($ts, $zid, $ztype, $zoff);
    $days = \__mc_fdiv($local, 86400);
    return \__mc_dt_utc($days * 86400 + $h * 3600 + $mi * 60 + $s, $zid, $ztype, $zoff);
}

/** Apply a strtotime modifier relative to this instant. */
function __mc_dt_modify(int $ts, int $zid, int $ztype, int $zoff, string $modifier): int
{
    if ($ztype === 3) {
        return \__mc_strtotime_core($modifier, $ts, $zid);
    }
    // A fixed-offset zone has no registry entry: shift into UTC-as-local,
    // parse there, and shift back.
    $r = \__mc_strtotime_core($modifier, $ts + $zoff, \__mc_tz_open('UTC'));
    if ($r === \__mc_dt_fail()) {
        return $r;
    }
    return $r - $zoff;
}

/**
 * Add ($sign * y/m/d h:i:s) to an instant, the way DateTime::add does: the
 * month is applied to the calendar field and the day is allowed to SPILL, so
 * Jan 31 + 1 month is March 3.
 */
function __mc_dt_shift(int $ts, int $zid, int $ztype, int $zoff,
    int $y, int $m, int $d, int $h, int $mi, int $s, int $sign): int
{
    $local = \__mc_dt_local($ts, $zid, $ztype, $zoff);
    $days = \__mc_fdiv($local, 86400);
    $tod = $local - $days * 86400;
    $p = \__mc_civil_from_days($days);
    $cy = \__mc_civ_y($p) + $sign * $y;
    $cm = \__mc_civ_m($p) + $sign * $m;
    $cd = \__mc_civ_d($p);
    $ny = \__mc_norm_ymd($cy, $cm, $cd, 0);
    $nm = \__mc_norm_ymd($cy, $cm, $cd, 1);
    $nd = \__mc_norm_ymd($cy, $cm, $cd, 2);
    $z = \__mc_days_from_civil($ny, $nm, $nd) + $sign * $d;
    $tod = $tod + $sign * ($h * 3600 + $mi * 60 + $s);
    $z = $z + \__mc_fdiv($tod, 86400);
    $tod = $tod - \__mc_fdiv($tod, 86400) * 86400;
    return \__mc_dt_utc($z * 86400 + $tod, $zid, $ztype, $zoff);
}

/**
 * Local wall-clock seconds plus N calendar months, with the SAME day-overflow
 * spill DateTime::add uses (Jan 31 + 1 month is March 3). Used by the diff
 * search below to ask whether one more month still fits.
 */
function __mc_dt_addmonths(int $local, int $months): int
{
    $days = \__mc_fdiv($local, 86400);
    $tod = $local - $days * 86400;
    $p = \__mc_civil_from_days($days);
    $cy = \__mc_civ_y($p);
    $cm = \__mc_civ_m($p) + $months;
    $cd = \__mc_civ_d($p);
    $ny = \__mc_norm_ymd($cy, $cm, $cd, 0);
    $nm = \__mc_norm_ymd($cy, $cm, $cd, 1);
    $nd = \__mc_norm_ymd($cy, $cm, $cd, 2);
    return \__mc_days_from_civil($ny, $nm, $nd) * 86400 + $tod;
}

/**
 * One field of the calendar difference between two instants, seen in the zone
 * of the FIRST one (which is what PHP uses).
 *
 * $which: 0 y, 1 m, 2 d, 3 h, 4 i, 5 s, 6 total days, 7 invert.
 *
 * The borrow chain is the delicate part: a negative day difference borrows the
 * length of the month BEFORE the later date, which is why the result of
 * 2017-01-01 -> 2018-03-15 is 1y 2m 14d rather than any equal-looking mix.
 */
function __mc_dt_diff(int $aTs, int $bTs, int $zid, int $ztype, int $zoff, int $which): int
{
    $invert = 0;
    $lo = $aTs;
    $hi = $bTs;
    if ($aTs > $bTs) {
        $invert = 1;
        $lo = $bTs;
        $hi = $aTs;
    }
    if ($which === 7) {
        return $invert;
    }
    $ll = \__mc_dt_local($lo, $zid, $ztype, $zoff);
    $lh = \__mc_dt_local($hi, $zid, $ztype, $zoff);
    if ($which === 6) {
        return \__mc_fdiv($lh - $ll, 86400);
    }
    // Count whole YEARS then whole MONTHS that still fit, then take the
    // remainder as elapsed time. A field-by-field subtract-and-borrow does NOT
    // work: from 2001-01-31 to 2001-03-01 it borrows February's 28 days and
    // still leaves the day negative. Advancing the anchor asks the question php
    // actually answers -- "does one more month still fit?" -- and one month
    // past Jan 31 is March 3 (the same spill DateTime::add has), which does not
    // fit, so the answer is 0 months and 29 days.
    $y = 0;
    while (\__mc_dt_addmonths($ll, ($y + 1) * 12) <= $lh) {
        $y = $y + 1;
    }
    $m = 0;
    while (\__mc_dt_addmonths($ll, $y * 12 + ($m + 1)) <= $lh) {
        $m = $m + 1;
    }
    $rem = $lh - \__mc_dt_addmonths($ll, $y * 12 + $m);
    $d = \__mc_fdiv($rem, 86400);
    $rem = $rem - $d * 86400;
    $h = \intdiv($rem, 3600);
    $i = \intdiv($rem % 3600, 60);
    $s = $rem % 60;

    if ($which === 0) { return $y; }
    if ($which === 1) { return $m; }
    if ($which === 2) { return $d; }
    if ($which === 3) { return $h; }
    if ($which === 4) { return $i; }
    return $s;
}

/**
 * DateTime::createFromFormat — a format-DRIVEN scan, distinct from strtotime's
 * recognizer bag: each format character says exactly what to consume next.
 * Returns a UTC-based instant built from the fields found, or the fail sentinel.
 */
function __mc_dt_from_format(string $format, string $value): int
{
    $y = -99999; $mo = -99999; $d = -99999;
    $h = -99999; $mi = -99999; $sec = -99999; $us = 0;
    $pm = -1; $zoff = 0; $haveZone = 0; $epoch = -99999;
    $vp = 0;
    $vn = \strlen($value);
    $fn = \strlen($format);
    $m = [];
    for ($k = 0; $k < $fn; $k++) {
        $c = $format[$k];
        $rest = \substr($value, $vp);
        if ($c === '\\') {
            $k = $k + 1;
            $vp = $vp + 1;
            continue;
        }
        if ($c === 'd' || $c === 'j') {
            if (\preg_match('/^(\d{1,2})/', $rest, $m) !== 1) { return \__mc_dt_fail(); }
            $d = (int)$m[1]; $vp = $vp + \strlen($m[1]); continue;
        }
        if ($c === 'm' || $c === 'n') {
            if (\preg_match('/^(\d{1,2})/', $rest, $m) !== 1) { return \__mc_dt_fail(); }
            $mo = (int)$m[1]; $vp = $vp + \strlen($m[1]); continue;
        }
        if ($c === 'Y') {
            if (\preg_match('/^(\d{4})/', $rest, $m) !== 1) { return \__mc_dt_fail(); }
            $y = (int)$m[1]; $vp = $vp + 4; continue;
        }
        if ($c === 'y') {
            if (\preg_match('/^(\d{2})/', $rest, $m) !== 1) { return \__mc_dt_fail(); }
            $y = \__mc_dt_y2((int)$m[1]); $vp = $vp + 2; continue;
        }
        if ($c === 'H' || $c === 'G' || $c === 'h' || $c === 'g') {
            if (\preg_match('/^(\d{1,2})/', $rest, $m) !== 1) { return \__mc_dt_fail(); }
            $h = (int)$m[1]; $vp = $vp + \strlen($m[1]); continue;
        }
        if ($c === 'i') {
            if (\preg_match('/^(\d{1,2})/', $rest, $m) !== 1) { return \__mc_dt_fail(); }
            $mi = (int)$m[1]; $vp = $vp + \strlen($m[1]); continue;
        }
        if ($c === 's') {
            if (\preg_match('/^(\d{1,2})/', $rest, $m) !== 1) { return \__mc_dt_fail(); }
            $sec = (int)$m[1]; $vp = $vp + \strlen($m[1]); continue;
        }
        if ($c === 'u' || $c === 'v') {
            if (\preg_match('/^(\d{1,6})/', $rest, $m) !== 1) { return \__mc_dt_fail(); }
            $us = (int)\substr($m[1] . '000000', 0, 6); $vp = $vp + \strlen($m[1]); continue;
        }
        if ($c === 'M' || $c === 'F') {
            if (\preg_match('/^([a-z]+)/i', $rest, $m) !== 1) { return \__mc_dt_fail(); }
            $mo = \__mc_dt_month(\strtolower($m[1]));
            if ($mo === 0) { return \__mc_dt_fail(); }
            $vp = $vp + \strlen($m[1]); continue;
        }
        if ($c === 'D' || $c === 'l') {
            if (\preg_match('/^([a-z]+)/i', $rest, $m) !== 1) { return \__mc_dt_fail(); }
            $vp = $vp + \strlen($m[1]); continue;
        }
        if ($c === 'a' || $c === 'A') {
            if (\preg_match('/^(am|pm)/i', $rest, $m) !== 1) { return \__mc_dt_fail(); }
            $pm = \strtolower($m[1]) === 'pm' ? 1 : 0;
            $vp = $vp + 2; continue;
        }
        if ($c === 'U') {
            if (\preg_match('/^(-?\d+)/', $rest, $m) !== 1) { return \__mc_dt_fail(); }
            $epoch = (int)$m[1]; $vp = $vp + \strlen($m[1]); continue;
        }
        if ($c === 'P' || $c === 'O') {
            if (\preg_match('/^([+-])(\d{2}):?(\d{2})/', $rest, $m) !== 1) { return \__mc_dt_fail(); }
            $o = (int)$m[2] * 3600 + (int)$m[3] * 60;
            $zoff = $m[1] === '-' ? -$o : $o;
            $haveZone = 1;
            $vp = $vp + \strlen($m[0]); continue;
        }
        if ($c === '?') { $vp = $vp + 1; continue; }
        if ($c === '*') {
            while ($vp < $vn && \preg_match('/^[a-z0-9]/i', \substr($value, $vp)) === 1) { $vp = $vp + 1; }
            continue;
        }
        if ($c === '!') {
            $y = 1970; $mo = 1; $d = 1; $h = 0; $mi = 0; $sec = 0; $us = 0;
            continue;
        }
        if ($c === '|') {
            if ($y === -99999) { $y = 1970; }
            if ($mo === -99999) { $mo = 1; }
            if ($d === -99999) { $d = 1; }
            if ($h === -99999) { $h = 0; }
            if ($mi === -99999) { $mi = 0; }
            if ($sec === -99999) { $sec = 0; }
            continue;
        }
        // A literal must match exactly.
        if ($vp >= $vn || $value[$vp] !== $c) {
            return \__mc_dt_fail();
        }
        $vp = $vp + 1;
    }
    if ($epoch !== -99999) {
        \__mc_dt_us(1, $us);
        return $epoch;
    }
    if ($pm === 1 && $h !== -99999 && $h < 12) { $h = $h + 12; }
    if ($pm === 0 && $h === 12) { $h = 0; }
    $now = \time();
    $bp = \__mc_civil_from_days(\__mc_fdiv($now, 86400));
    if ($y === -99999) { $y = \__mc_civ_y($bp); }
    if ($mo === -99999) { $mo = \__mc_civ_m($bp); }
    if ($d === -99999) { $d = \__mc_civ_d($bp); }
    if ($h === -99999) { $h = 0; }
    if ($mi === -99999) { $mi = 0; }
    if ($sec === -99999) { $sec = 0; }
    \__mc_dt_us(1, $us);
    $local = \__mc_days_from_civil($y, $mo, $d) * 86400 + $h * 3600 + $mi * 60 + $sec;
    return $local - $zoff;
}

/**
 * Every CANONICAL zone name, sorted.
 *
 * Read from `zone.tab` rather than by walking the directory: the tree also
 * holds backward-compatibility LINKS (Africa/Bamako -> Africa/Abidjan), and a
 * walk returned 597 names where php reports 419. zone.tab lists exactly the
 * canonical set, and UTC — which has no country and so no row — is added.
 *
 * The element type is declared so the caller reads real strings, not tagged
 * bits.
 *
 * @return string[]
 */
function timezone_identifiers_list(int $timezoneGroup = 2047, ?string $countryCode = null): array
{
    /** @var string[] $out */
    $out = [];
    $raw = \file_get_contents(\__mc_tzdir() . '/zone.tab');
    if ($raw === false) {
        return $out;
    }
    $lines = \explode("\n", $raw);
    foreach ($lines as $line) {
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        // country \t coordinates \t name [\t comments]
        $cols = \explode("\t", $line);
        if (\count($cols) < 3) {
            continue;
        }
        if ($countryCode !== null && $countryCode !== '' && $cols[0] !== $countryCode) {
            continue;
        }
        $out[] = \trim($cols[2]);
    }
    if ($countryCode === null || $countryCode === '') {
        $out[] = 'UTC';
    }
    \sort($out);
    return $out;
}

/**
 * Country and coordinates for a zone, from zone.tab, or an empty result when
 * the zone has no row (UTC and the like).
 *
 * @return array<string, mixed>
 */
function __mc_tz_location(string $name): array
{
    $out = ['country_code' => '??', 'latitude' => 0.0, 'longitude' => 0.0, 'comments' => ''];
    $raw = \file_get_contents(\__mc_tzdir() . '/zone.tab');
    if ($raw === false) {
        return $out;
    }
    foreach (\explode("\n", $raw) as $line) {
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $cols = \explode("\t", $line);
        if (\count($cols) < 3 || \trim($cols[2]) !== $name) {
            continue;
        }
        $out['country_code'] = $cols[0];
        // ISO 6709: +DDMM(SS)+DDDMM(SS)
        $c = $cols[1];
        $split = 1;
        $n = \strlen($c);
        for ($i = 1; $i < $n; $i++) {
            if ($c[$i] === '+' || $c[$i] === '-') {
                $split = $i;
                break;
            }
        }
        $out['latitude'] = \__mc_iso6709(\substr($c, 0, $split));
        $out['longitude'] = \__mc_iso6709(\substr($c, $split));
        $out['comments'] = \count($cols) > 3 ? \trim($cols[3]) : '';
        return $out;
    }
    return $out;
}

/** One ISO 6709 coordinate (+DDMM[SS] or +DDDMM[SS]) to signed degrees. */
function __mc_iso6709(string $s): float
{
    if (\strlen($s) < 5) {
        return 0.0;
    }
    $sign = $s[0] === '-' ? -1.0 : 1.0;
    $body = \substr($s, 1);
    // Degrees take 2 digits for a latitude, 3 for a longitude; the rest is
    // MM or MMSS.
    $degLen = \strlen($body) === 4 || \strlen($body) === 6 ? 2 : 3;
    $deg = (float)\substr($body, 0, $degLen);
    $min = (float)\substr($body, $degLen, 2);
    $sec = \strlen($body) > $degLen + 2 ? (float)\substr($body, $degLen + 2, 2) : 0.0;
    return $sign * ($deg + $min / 60.0 + $sec / 3600.0);
}

// NOTE: the procedural aliases that take a DateTimeZone / DateTimeInterface
// (timezone_offset_get, date_diff, date_add, ...) CANNOT live here. A stdlib
// signature naming a prelude class would drag the class into the .sig, and a
// class in the .sig has to be registered in EVERY module -- which is exactly
// what forces \Resource to be unconditional. Those aliases belong in the
// prelude, beside the classes they mention.
