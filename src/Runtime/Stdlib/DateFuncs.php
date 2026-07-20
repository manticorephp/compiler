<?php

/**
 * The procedural date/time surface. Thin wrappers over the civil core, the zone
 * registry and the format engine — all the real work lives there.
 */

/** Zone handle for the current default timezone. */
function __mc_tz_current(): int
{
    return \__mc_tz_open(\date_default_timezone_get());
}

/** Format a timestamp in the default timezone. */
function date(string $format, ?int $timestamp = null): string
{
    $ts = $timestamp === null ? \time() : $timestamp;
    $zn = \date_default_timezone_get();
    return \__mc_date_fmt($format, $ts, 0, \__mc_tz_open($zn), $zn, 3, 0);
}

/** Format a timestamp in UTC. */
function gmdate(string $format, ?int $timestamp = null): string
{
    $ts = $timestamp === null ? \time() : $timestamp;
    // Type 4, not the 'UTC' zone: gmdate's 'T' is 'GMT' while its 'e' is 'UTC'.
    return \__mc_date_fmt($format, $ts, 0, 0, 'UTC', 4, 0);
}

/** date() returning an int, for the numeric specifiers. */
function idate(string $format, ?int $timestamp = null): int
{
    return (int)\date($format, $timestamp);
}

/**
 * Timestamp from local civil fields in the default timezone. Out-of-range
 * fields normalize the way PHP's do, so mktime(0,0,0,13,1,2001) is 2002-01-01.
 */
function mktime(?int $hour = null, ?int $minute = null, ?int $second = null,
    ?int $month = null, ?int $day = null, ?int $year = null): int
{
    $zid = \__mc_tz_current();
    $now = \time();
    $off = \__mc_tz_offset($zid, $now);
    $ldays = \__mc_fdiv($now + $off, 86400);
    $lsod = ($now + $off) - $ldays * 86400;
    $p = \__mc_civil_from_days($ldays);
    $h = $hour === null ? \intdiv($lsod, 3600) : $hour;
    $mi = $minute === null ? \intdiv($lsod % 3600, 60) : $minute;
    $s = $second === null ? $lsod % 60 : $second;
    $mo = $month === null ? \__mc_civ_m($p) : $month;
    $d = $day === null ? \__mc_civ_d($p) : $day;
    $y = $year === null ? \__mc_civ_y($p) : $year;
    $ny = \__mc_norm_ymd($y, $mo, $d, 0);
    $nm = \__mc_norm_ymd($y, $mo, $d, 1);
    $nd = \__mc_norm_ymd($y, $mo, $d, 2);
    $local = \__mc_days_from_civil($ny, $nm, $nd) * 86400 + $h * 3600 + $mi * 60 + $s;
    return \__mc_tz_localtoutc($zid, $local);
}

/** mktime() in UTC. */
function gmmktime(?int $hour = null, ?int $minute = null, ?int $second = null,
    ?int $month = null, ?int $day = null, ?int $year = null): int
{
    $now = \time();
    $ldays = \__mc_fdiv($now, 86400);
    $lsod = $now - $ldays * 86400;
    $p = \__mc_civil_from_days($ldays);
    $h = $hour === null ? \intdiv($lsod, 3600) : $hour;
    $mi = $minute === null ? \intdiv($lsod % 3600, 60) : $minute;
    $s = $second === null ? $lsod % 60 : $second;
    $mo = $month === null ? \__mc_civ_m($p) : $month;
    $d = $day === null ? \__mc_civ_d($p) : $day;
    $y = $year === null ? \__mc_civ_y($p) : $year;
    $ny = \__mc_norm_ymd($y, $mo, $d, 0);
    $nm = \__mc_norm_ymd($y, $mo, $d, 1);
    $nd = \__mc_norm_ymd($y, $mo, $d, 2);
    return \__mc_days_from_civil($ny, $nm, $nd) * 86400 + $h * 3600 + $mi * 60 + $s;
}

/** Whether a Gregorian date exists. */
function checkdate(int $month, int $day, int $year): bool
{
    if ($month < 1 || $month > 12 || $year < 1 || $year > 32767) {
        return false;
    }
    if ($day < 1) {
        return false;
    }
    return $day <= \__mc_days_in_month($year, $month);
}

/**
 * Date/time parts as an assoc. The array is built HERE, at the return site,
 * with every element a concrete scalar — nothing partially-typed is ever
 * handed across a call boundary.
 *
 * @return array{seconds:int,minutes:int,hours:int,mday:int,wday:int,mon:int,year:int,yday:int,weekday:string,month:string,0:int}
 */
function getdate(?int $timestamp = null): array
{
    $ts = $timestamp === null ? \time() : $timestamp;
    $zn = \date_default_timezone_get();
    $zid = \__mc_tz_open($zn);
    $local = $ts + \__mc_tz_offset($zid, $ts);
    $days = \__mc_fdiv($local, 86400);
    $sod = $local - $days * 86400;
    $p = \__mc_civil_from_days($days);
    $y = \__mc_civ_y($p);
    $mo = \__mc_civ_m($p);
    $d = \__mc_civ_d($p);
    return [
        'seconds' => $sod % 60,
        'minutes' => \intdiv($sod % 3600, 60),
        'hours' => \intdiv($sod, 3600),
        'mday' => $d,
        'wday' => \__mc_dow($days),
        'mon' => $mo,
        'year' => $y,
        'yday' => \__mc_doy($y, $mo, $d) - 1,
        'weekday' => \__mc_dayname(\__mc_dow($days)),
        'month' => \__mc_monthname($mo),
        0 => $ts,
    ];
}

/**
 * The C `struct tm` fields. $associative selects string keys over numeric ones,
 * matching PHP.
 *
 * @return array<string|int, int>
 */
function localtime(?int $timestamp = null, bool $associative = false): array
{
    $ts = $timestamp === null ? \time() : $timestamp;
    $zn = \date_default_timezone_get();
    $zid = \__mc_tz_open($zn);
    $local = $ts + \__mc_tz_offset($zid, $ts);
    $days = \__mc_fdiv($local, 86400);
    $sod = $local - $days * 86400;
    $p = \__mc_civil_from_days($days);
    $y = \__mc_civ_y($p);
    $mo = \__mc_civ_m($p);
    $d = \__mc_civ_d($p);
    $sec = $sod % 60;
    $min = \intdiv($sod % 3600, 60);
    $hour = \intdiv($sod, 3600);
    $wday = \__mc_dow($days);
    $yday = \__mc_doy($y, $mo, $d) - 1;
    $isdst = \__mc_tz_isdst($zid, $ts);
    if ($associative) {
        return ['tm_sec' => $sec, 'tm_min' => $min, 'tm_hour' => $hour,
            'tm_mday' => $d, 'tm_mon' => $mo - 1, 'tm_year' => $y - 1900,
            'tm_wday' => $wday, 'tm_yday' => $yday, 'tm_isdst' => $isdst];
    }
    return [$sec, $min, $hour, $d, $mo - 1, $y - 1900, $wday, $yday, $isdst];
}
