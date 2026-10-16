<?php

namespace Cli;

/** ANSI styling for human-facing stderr output; a no-op until {@see enable}d. */
final class Style
{
    private static bool $on = false;

    public static function enable(bool $on): void { self::$on = $on; }

    public static function enabled(): bool { return self::$on; }

    public static function bold(string $s): string { return self::wrap('1', $s); }

    public static function dim(string $s): string { return self::wrap('2', $s); }

    public static function cyan(string $s): string { return self::wrap('36', $s); }

    public static function green(string $s): string { return self::wrap('32', $s); }

    public static function yellow(string $s): string { return self::wrap('33', $s); }

    private static function wrap(string $code, string $s): string
    {
        if (!self::$on) { return $s; }
        return "\033[" . $code . 'm' . $s . "\033[0m";
    }
}
