<?php

namespace Compile;

/**
 * Compile-time profile (`MANTICORE_STATS=1`). Off by default; every hook is
 * behind `Stats::$on`, so a normal build pays one boolean test per pass.
 *
 * The compiler had NO time or memory instrumentation at all, which is why a
 * build that hangs on a large program (a vendor tree, not our own src/) could
 * only be diagnosed by attaching a debugger. This reports, on stderr:
 *
 *   stats: <elapsed>ms +<pass>ms  <name>  fns=<n> cls=<n>
 *
 * plus per-round lines from the two fixpoint passes (Monomorphize,
 * NarrowReturns) whose iteration caps scale with the function count, and a
 * tail of counters for the whole-program scans that are quadratic by
 * construction.
 *
 * Time comes from `hrtime(true)`: a real php function under the Zend cold
 * seed AND a stdlib function natively, so the same code reports in both.
 * RESIDENT MEMORY is deliberately NOT read here — there is no native
 * memory_get_usage(); sample RSS from outside the process and correlate on the
 * elapsed-ms column these lines carry.
 */
final class Stats
{
    public static bool $on = false;

    /** Nanosecond clock reading at compiler startup. */
    private static int $t0 = 0;
    /** Independent root-telemetry clock. */
    private static int $rootT0 = 0;

    /** @var array<string, int> counter name → total */
    private static array $counts = [];

    public static function init(): void
    {
        if (!self::$on) { return; }
        self::$t0 = self::now();
    }

    public static function rootInit(): void
    {
        self::$rootT0 = self::now();
    }

    public static function rootLine(string $s): void
    {
        $ms = self::$rootT0 > 0
            ? \intdiv(self::now() - self::$rootT0, 1000000) : 0;
        \error_log('stats: ' . (string)$ms . 'ms  ' . $s);
    }

    /** Monotonic nanoseconds. */
    public static function now(): int
    {
        return (int)\hrtime(true);
    }

    /** Milliseconds since {@see init}. */
    private static function elapsedMs(): int
    {
        return \intdiv(self::now() - self::$t0, 1000000);
    }

    /**
     * Report one pipeline step: its wall time, and the module size after it.
     * Pass -1 for a count that does not apply (e.g. a front-end phase).
     */
    public static function step(string $name, int $startNs, int $fns, int $classes): void
    {
        if (!self::$on) { return; }
        $ms = \intdiv(self::now() - $startNs, 1000000);
        $line = 'stats: ' . (string)self::elapsedMs() . 'ms +' . (string)$ms . 'ms  ' . $name;
        if ($fns >= 0) { $line = $line . '  fns=' . (string)$fns; }
        if ($classes >= 0) { $line = $line . ' cls=' . (string)$classes; }
        \error_log($line);
    }

    /** A free-form stats line, stamped with the elapsed time. */
    public static function line(string $s): void
    {
        if (!self::$on) { return; }
        \error_log('stats: ' . (string)self::elapsedMs() . 'ms  ' . $s);
    }

    /** Add to a named counter (whole-program scan hits, inner iterations, …). */
    public static function bump(string $key, int $n): void
    {
        if (!self::$on) { return; }
        $cur = 0;
        if (isset(self::$counts[$key])) { $cur = self::$counts[$key]; }
        self::$counts[$key] = $cur + $n;
    }

    /** Print every counter. Called once at the end of a build. */
    public static function dumpCounters(): void
    {
        if (!self::$on) { return; }
        foreach (self::$counts as $k => $v) {
            \error_log('stats: counter ' . $k . ' = ' . (string)$v);
        }
    }
}
