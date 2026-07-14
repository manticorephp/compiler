<?php

/**
 * Clocks. All three sit on ONE codegen builtin, `__mir_clock_ns($clock)`, which
 * returns nanoseconds from a POSIX `clock_gettime`. The argument is a LOGICAL
 * clock id — 0 wall-clock, 1 monotonic — because the OS ids disagree
 * (CLOCK_MONOTONIC is 1 on Linux, 6 on Darwin); the emitter maps it to the
 * host's own id. Nanoseconds in an i64 overflow in the year 2262, which the
 * `float` seconds of PHP's own API would lose long before.
 */

/** Wall-clock seconds since the Unix epoch. */
function time(): int
{
    return \intdiv(__mir_clock_ns(0), 1000000000);
}

/**
 * Wall-clock time. `microtime(true)` is float seconds; the default returns
 * PHP's "0.86688700 1720000000" string — the fraction to 8 places, a space,
 * then the whole seconds.
 */
function microtime(bool $as_float = false): mixed
{
    $ns = __mir_clock_ns(0);
    if ($as_float) {
        return (float)$ns / 1000000000.0;
    }
    $sec = \intdiv($ns, 1000000000);
    $frac = (float)($ns - $sec * 1000000000) / 1000000000.0;
    return \sprintf("%.8F %d", $frac, $sec);
}

/**
 * The MONOTONIC clock — the one to measure an elapsed interval with (the wall
 * clock can step). `hrtime(true)` is nanoseconds as an int; the default is
 * PHP's [seconds, nanoseconds] pair.
 * @return int|int[]
 */
function hrtime(bool $as_number = false): mixed
{
    $ns = __mir_clock_ns(1);
    if ($as_number) {
        return $ns;
    }
    $sec = \intdiv($ns, 1000000000);
    return [$sec, $ns - $sec * 1000000000];
}
