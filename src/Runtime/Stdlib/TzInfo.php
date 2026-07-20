<?php

/**
 * Zone database location, the default-timezone setting, and the small typed
 * wrappers the rest of the date/time area uses instead of raw `__mc_tz` ops.
 */

/**
 * Root of the tz database.
 *
 * No Darwin branch is needed: macOS ships `/usr/share/zoneinfo` as a symlink to
 * the versioned `/var/db/timezone/...` tree, and `fopen` follows it. Deliberate
 * — one less host branch is one less silent-divergence site.
 */
function __mc_tzdir(): string
{
    static $dir = '';
    if ($dir !== '') {
        return $dir;
    }
    $env = \getenv('TZDIR');
    if ($env !== false && $env !== '' && \is_dir($env)) {
        $dir = $env;
        return $dir;
    }
    if (\is_dir('/usr/share/zoneinfo')) {
        $dir = '/usr/share/zoneinfo';
        return $dir;
    }
    if (\is_dir('/usr/share/lib/zoneinfo')) {
        $dir = '/usr/share/lib/zoneinfo';
        return $dir;
    }
    $dir = '/usr/lib/locale/TZ';
    return $dir;
}

/** The host's configured zone name, from /etc/localtime, else UTC. */
function __mc_tz_hostzone(): string
{
    $link = \readlink('/etc/localtime');
    if ($link !== false) {
        $at = \strpos($link, 'zoneinfo/');
        if ($at !== false) {
            $n = \substr($link, $at + 9);
            if (\__mc_tz_valid_name($n)) {
                return $n;
            }
        }
    }
    $etc = \file_get_contents('/etc/timezone');
    if ($etc !== false) {
        $n = \trim($etc);
        if (\__mc_tz_valid_name($n)) {
            return $n;
        }
    }
    return 'UTC';
}

/**
 * Default-timezone slot. $op 0 reads, 1 writes (returning '' if the name is not
 * a usable zone). Both public functions share this because two functions cannot
 * share a `static`.
 */
function __mc_tz_default(int $op, string $v): string
{
    static $tz = '';
    if ($op === 1) {
        if (\__mc_tz(0, 0, 0, $v) < 0) {
            return '';
        }
        $tz = $v;
        return $tz;
    }
    if ($tz === '') {
        $h = \__mc_tz_hostzone();
        if (\__mc_tz(0, 0, 0, $h) < 0) {
            $h = 'UTC';
        }
        $tz = $h;
    }
    return $tz;
}

/** Timezone used by every date/time function that is not given one. */
function date_default_timezone_get(): string
{
    return \__mc_tz_default(0, '');
}

/** Set the default timezone. False (PHP <8 warned) when the name is unknown. */
function date_default_timezone_set(string $timezoneId): bool
{
    return \__mc_tz_default(1, $timezoneId) !== '';
}

/** Zone handle for a name, or -1. */
function __mc_tz_open(string $name): int
{
    return \__mc_tz(0, 0, 0, $name);
}

/** Seconds east of UTC in $zid at the UTC instant $ts. */
function __mc_tz_offset(int $zid, int $ts): int
{
    return \__mc_tz(10, $zid, $ts);
}

/** Whether DST is in force in $zid at the UTC instant $ts. */
function __mc_tz_isdst(int $zid, int $ts): int
{
    return \__mc_tz(5, $zid, \__mc_tz(8, $zid, $ts));
}

/** Abbreviation ("EEST") in force in $zid at the UTC instant $ts. */
function __mc_tz_abbr(int $zid, int $ts): string
{
    return \__mc_tz_unpackabbr(\__mc_tz(6, $zid, \__mc_tz(8, $zid, $ts)));
}

/**
 * UTC instant for a local wall-clock instant in $zid. Gap and fold are resolved
 * the way PHP does: a time inside a spring-forward gap moves forward, and an
 * ambiguous time inside a fall-back fold takes the first (still-DST) occurrence.
 */
function __mc_tz_localtoutc(int $zid, int $local): int
{
    return $local - \__mc_tz(4, $zid, \__mc_tz(9, $zid, $local));
}
