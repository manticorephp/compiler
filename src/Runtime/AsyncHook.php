<?php

namespace Runtime;

/**
 * The netpoller seam. When an async scheduler is running it installs three
 * callbacks here; the stdlib stream layer (fread/fwrite/accept/fclose) consults
 * them so ordinary blocking-LOOKING I/O transparently suspends the current fiber
 * instead of blocking the whole process — the Go netpoller model.
 *
 * All three are `callable(\Resource): void` and null when no scheduler is active
 * (a plain synchronous program pays nothing: one null check per would-block).
 * Installed once before any fiber runs and cleared when {@see Async\async()}
 * returns, so they are read-only for the lifetime of the loop — safe to share
 * across fibers. Access goes through static methods because a static property is
 * only assignable from inside its own class (`self::$x =`).
 */
final class AsyncHook
{
    public static mixed $waitReadable = null;
    public static mixed $waitWritable = null;
    public static mixed $onClose = null;

    public static function install(mixed $waitReadable, mixed $waitWritable, mixed $onClose): void
    {
        self::$waitReadable = $waitReadable;
        self::$waitWritable = $waitWritable;
        self::$onClose = $onClose;
    }

    public static function clear(): void
    {
        self::$waitReadable = null;
        self::$waitWritable = null;
        self::$onClose = null;
    }

    /** True while a scheduler is driving I/O and a fiber is running. */
    public static function active(): bool
    {
        return self::$waitReadable !== null && \Fiber::getCurrent() !== null;
    }

    public static function readable(): mixed { return self::$waitReadable; }
    public static function writable(): mixed { return self::$waitWritable; }
    public static function closer(): mixed { return self::$onClose; }
}
