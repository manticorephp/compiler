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
 * So does memory: every line carries `rss=<n>MB` from `memory_get_usage()`,
 * which natively answers the process's PEAK resident size (getrusage
 * `ru_maxrss` — {@see \__mc_rss_bytes}). Peak rather than current is the right
 * reading for attribution: it never goes down, so the phase whose line jumps is
 * the phase that allocated. An outside sampler still gives the shape between
 * phases; this gives the boundaries a sampler cannot name.
 */
final class Stats
{
    public static bool $on = false;

    /**
     * `MANTICORE_PHASE_TRACE=1` — the per-phase timeline ONLY: {@see step} and
     * {@see line} report, while the counters and every other `Stats::$on` hook
     * stay off.
     *
     * It exists because `MANTICORE_STATS=1` cannot be used on a large target.
     * It also enables the fat-function report in `EmitLlvm`, whose
     * `Stats::line()` allocation lands in the buffer of a BORROWED array
     * element that the emitter still holds (`$rawBodyPath = $meta[1]` outliving
     * `unset($meta)`), and the build dies with the stats text spliced into the
     * exception message. That bug predates this flag — gen-1 and gen-3 fail
     * identically — and is recorded as its own root; a phase timeline must not
     * wait on it.
     */
    public static bool $phaseTrace = false;

    /** Is any reporting on? */
    private static function reporting(): bool
    {
        return self::$on || self::$phaseTrace;
    }

    /** `rss=<n>MB`, the process peak. Read once per phase, never in a loop. */
    private static function rss(): string
    {
        return '  rss=' . (string)\intdiv(\memory_get_usage(), 1048576) . 'MB';
    }

    /** Nanosecond clock reading at compiler startup. */
    private static int $t0 = 0;
    /** Independent root-telemetry clock. */
    private static int $rootT0 = 0;

    /** @var array<string, int> counter name → total */
    private static array $counts = [];

    public static function init(): void
    {
        if (!self::reporting()) { return; }
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
        if (!self::reporting()) { return; }
        $ms = \intdiv(self::now() - $startNs, 1000000);
        $line = 'stats: ' . (string)self::elapsedMs() . 'ms +' . (string)$ms . 'ms  ' . $name;
        if ($fns >= 0) { $line = $line . '  fns=' . (string)$fns; }
        if ($classes >= 0) { $line = $line . ' cls=' . (string)$classes; }
        \error_log($line . self::rss());
    }

    /** A free-form stats line, stamped with the elapsed time. */
    public static function line(string $s): void
    {
        if (!self::reporting()) { return; }
        \error_log('stats: ' . (string)self::elapsedMs() . 'ms  ' . $s . self::rss());
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
