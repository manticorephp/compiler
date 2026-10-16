<?php

namespace Compile;

/**
 * Build progress on stderr (stdout is never touched). Modes: live (one
 * redrawn line, TTY), plain (one line per finished stage), json (one event per
 * line), off. Configured once per command by {@see configure}; every hook is a
 * no-op while the mode is off.
 */
final class Progress
{
    private static string $mode = 'off';
    private static bool $timings = false;
    private static int $t0 = 0;
    private static int $finished = 0;
    private static string $running = '';
    private static int $runStart = 0;
    private static string $counter = '';
    private static bool $liveOpen = false;
    /** @var string[] */
    private static array $names = [];
    /** @var int[] */
    private static array $times = [];

    /** @return string '' when ok, else the error text */
    public static function configure(string $progress, bool $quiet, bool $timings, string $color): string
    {
        if ($progress !== 'auto' && $progress !== 'live' && $progress !== 'plain' && $progress !== 'json' && $progress !== 'off') {
            return "invalid --progress value '" . $progress . "' (auto|live|plain|json|off)";
        }
        if ($color !== 'auto' && $color !== 'always' && $color !== 'never') {
            return "invalid --color value '" . $color . "' (auto|always|never)";
        }
        self::$mode = 'off';
        self::$timings = false;
        self::$finished = 0;
        self::$running = '';
        self::$counter = '';
        self::$liveOpen = false;
        self::$names = [];
        self::$times = [];
        \Cli\Style::enable(false);
        if ($quiet) { return ''; }
        $tty = \Manticore\sys_isatty(2) === 1;
        $mode = $progress;
        if ($mode === 'auto') {
            $ci = \getenv('CI');
            if ($tty) { $mode = 'live'; }
            elseif (\is_string($ci) && $ci !== '') { $mode = 'plain'; }
            else { $mode = 'off'; }
        }
        self::$mode = $mode;
        self::$timings = $timings;
        self::$t0 = Stats::now();
        $noColor = \getenv('NO_COLOR');
        $term = \getenv('TERM');
        $on = false;
        if ($color === 'never') { $on = false; }
        elseif (\is_string($noColor) && $noColor !== '') { $on = false; }
        elseif (\is_string($term) && $term === 'dumb') { $on = false; }
        elseif ($color === 'always') { $on = true; }
        else { $on = $tty; }
        if ($mode === 'json') { $on = false; }
        if ($mode === 'plain' && $color !== 'always') { $on = false; }
        \Cli\Style::enable($on);
        return '';
    }

    public static function active(): bool { return self::$mode !== 'off'; }

    public static function begin(string $name): void
    {
        if (self::$mode === 'off') { return; }
        self::$running = \trim($name);
        self::$runStart = Stats::now();
        if (self::$mode === 'json') {
            self::emit('{"event":"stage_start","name":"' . self::esc(self::$running) . '","t_ms":' . (string)self::totalMs() . '}');
        } elseif (self::$mode === 'live') {
            self::redraw();
        }
    }

    public static function end(string $name, int $ms, int $fns, int $classes): void
    {
        if (self::$mode === 'off') { return; }
        $n = \trim($name);
        self::$finished = self::$finished + 1;
        self::$names[] = $n;
        self::$times[] = $ms;
        self::$running = '';
        if (self::$mode === 'json') {
            $l = '{"event":"stage_end","name":"' . self::esc($n) . '","ms":' . (string)$ms;
            if ($fns >= 0) { $l = $l . ',"fns":' . (string)$fns; }
            if ($classes >= 0) { $l = $l . ',"classes":' . (string)$classes; }
            self::emit($l . '}');
        } elseif (self::$mode === 'plain') {
            self::emit('[' . \str_pad((string)self::$finished, 2, ' ', \STR_PAD_LEFT) . '] ' . $n . '  ' . \Cli\Style::dim(self::sec($ms)));
        } else {
            self::clear();
            self::$counter = '';
        }
    }

    public static function counter(string $label, int $done, int $total): void
    {
        if (self::$mode === 'off') { return; }
        if (self::$mode === 'json') {
            self::emit('{"event":"counter","label":"' . self::esc($label) . '","done":' . (string)$done . ',"total":' . (string)$total . '}');
        } elseif (self::$mode === 'plain') {
            if ($done === $total) { self::emit('      ' . $label . ' ' . (string)$done . '/' . (string)$total); }
        } else {
            self::$counter = $label . ' ' . (string)$done . '/' . (string)$total;
            self::redraw();
        }
    }

    /** Erase an open live line. */
    public static function clear(): void
    {
        if (!self::$liveOpen) { return; }
        self::$liveOpen = false;
        self::raw("\r\033[2K");
    }

    public static function finish(int $rc): void
    {
        if (self::$mode === 'off') { return; }
        self::clear();
        $total = self::totalMs();
        if (self::$mode === 'json') {
            self::emit('{"event":"done","ms":' . (string)$total . ',"rc":' . (string)$rc . '}');
        } else {
            $msg = 'done in ' . self::sec($total);
            if ($rc !== 0) { $msg = 'failed after ' . self::sec($total); }
            if (self::$mode === 'live') {
                $msg = ($rc === 0 ? '✓ ' : '✗ ') . $msg;
            }
            self::emit($rc === 0 ? \Cli\Style::green($msg) : \Cli\Style::yellow($msg));
        }
        if (self::$timings) { self::printTimings(); }
    }

    private static function printTimings(): void
    {
        $n = \count(self::$times);
        /** @var int[] $order */
        $order = [];
        for ($i = 0; $i < $n; $i++) { $order[] = $i; }
        // insertion sort, longest first (stable); n is a few dozen
        for ($i = 1; $i < $n; $i++) {
            $k = $order[$i];
            $j = $i - 1;
            while ($j >= 0 && self::$times[$order[$j]] < self::$times[$k]) {
                $order[$j + 1] = $order[$j];
                $j = $j - 1;
            }
            $order[$j + 1] = $k;
        }
        $top = $n < 15 ? $n : 15;
        if (self::$mode !== 'json') { self::emit('timings (longest first):'); }
        for ($i = 0; $i < $top; $i++) {
            $ms = self::$times[$order[$i]];
            $name = self::$names[$order[$i]];
            if (self::$mode === 'json') {
                self::emit('{"event":"timing","name":"' . self::esc($name) . '","ms":' . (string)$ms . '}');
            } else {
                self::emit('  ' . \str_pad((string)$ms, 6, ' ', \STR_PAD_LEFT) . 'ms  ' . $name);
            }
        }
    }

    private static function redraw(): void
    {
        $now = Stats::now();
        $body = self::$running;
        if ($body === '') { $body = 'working'; }
        $body = $body . '  ' . self::sec(\intdiv($now - self::$runStart, 1000000))
            . '  (total ' . self::sec(self::totalMs()) . ')';
        if (self::$counter !== '') { $body = $body . ' · ' . self::$counter; }
        if (\strlen($body) > 77) { $body = \substr($body, 0, 76) . '…'; }
        self::raw("\r\033[2K" . \Cli\Style::cyan('●') . ' ' . $body);
        self::$liveOpen = true;
    }

    private static function totalMs(): int { return \intdiv(Stats::now() - self::$t0, 1000000); }

    private static function sec(int $ms): string
    {
        return (string)\intdiv($ms, 1000) . '.' . (string)\intdiv($ms % 1000, 100) . 's';
    }

    /** A finished line (newline-terminated); clears an open live line first. */
    private static function emit(string $line): void
    {
        self::clear();
        \error_log($line);
    }

    private static function raw(string $s): void
    {
        \Manticore\write(2, $s, \strlen($s));
    }

    private static function esc(string $s): string
    {
        $out = '';
        $n = \strlen($s);
        for ($i = 0; $i < $n; $i++) {
            $c = $s[$i];
            $o = \ord($c);
            if ($c === '"') { $out = $out . '\\"'; }
            elseif ($c === '\\') { $out = $out . '\\\\'; }
            elseif ($o < 32) { $out = $out . '\\u00' . \substr('0123456789abcdef', \intdiv($o, 16), 1) . \substr('0123456789abcdef', $o % 16, 1); }
            else { $out = $out . $c; }
        }
        return $out;
    }
}
