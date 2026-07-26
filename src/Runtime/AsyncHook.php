<?php

namespace Runtime;

/**
 * The netpoller seam. When an async scheduler is running it installs its
 * callbacks here; the stdlib stream layer (fread/fwrite/accept/fclose) consults
 * them so ordinary blocking-LOOKING I/O transparently suspends the current fiber
 * instead of blocking the whole process — the Go netpoller model.
 *
 * `$waitReadable`/`$waitWritable`/`$onClose` are `callable(\Resource): void`;
 * `$waitReadableFor` is `callable(\Resource, float): bool` — a BOUNDED readable
 * wait that answers false on expiry, which is what makes stream_set_timeout(),
 * stream_socket_accept($timeout) and the DNS exchange's 5 s budget observable
 * under a scheduler instead of parking forever. `$sleeper` is
 * `callable(float): void` — sleep()/usleep() suspend the fiber, not the process.
 *
 * All are null when no scheduler is active (a plain synchronous program pays
 * nothing: one null check per would-block). Installed once before any fiber runs
 * and cleared when {@see Async\async()} returns, so they are read-only for the
 * lifetime of the loop — safe to share across fibers. Access goes through static
 * methods because a static property is only assignable from inside its own class
 * (`self::$x =`).
 */
final class AsyncHook
{
    public static mixed $waitReadable = null;
    public static mixed $waitWritable = null;
    public static mixed $onClose = null;
    public static mixed $waitReadableFor = null;
    public static mixed $waitWritableFor = null;
    public static mixed $sleeper = null;
    public static mixed $dnsGet = null;
    public static mixed $dnsPut = null;

    public static function install(mixed $waitReadable, mixed $waitWritable, mixed $onClose,
                                   mixed $waitReadableFor, mixed $waitWritableFor,
                                   mixed $sleeper, mixed $dnsGet, mixed $dnsPut): void
    {
        self::$waitReadable = $waitReadable;
        self::$waitWritable = $waitWritable;
        self::$onClose = $onClose;
        self::$waitReadableFor = $waitReadableFor;
        self::$waitWritableFor = $waitWritableFor;
        self::$sleeper = $sleeper;
        self::$dnsGet = $dnsGet;
        self::$dnsPut = $dnsPut;
    }

    public static function clear(): void
    {
        self::$waitReadable = null;
        self::$waitWritable = null;
        self::$onClose = null;
        self::$waitReadableFor = null;
        self::$waitWritableFor = null;
        self::$sleeper = null;
        self::$dnsGet = null;
        self::$dnsPut = null;
    }

    /** True while a scheduler is driving I/O and a fiber is running. */
    public static function active(): bool
    {
        return self::$waitReadable !== null && \Fiber::getCurrent() !== null;
    }

    public static function readable(): mixed { return self::$waitReadable; }
    public static function writable(): mixed { return self::$waitWritable; }
    public static function closer(): mixed { return self::$onClose; }
    /** Bounded readable wait: `fn(\Resource, float $seconds): bool`. */
    public static function readableFor(): mixed { return self::$waitReadableFor; }
    /** Bounded writable wait: `fn(\Resource, float $seconds): bool`. */
    public static function writableFor(): mixed { return self::$waitWritableFor; }
    /** Fiber-aware sleep: `fn(float $seconds): void`. */
    public static function sleeper(): mixed { return self::$sleeper; }
    /**
     * Resolver cache, held by the SCHEDULER because a stdlib static cannot own an
     * assoc (the array-repr trap) and a per-run lifetime is the right scope anyway.
     * `dnsGet: fn(string $host): string` ('' = miss/expired) ·
     * `dnsPut: fn(string $host, string $ip, int $ttl): void`.
     */
    public static function dnsGetter(): mixed { return self::$dnsGet; }
    public static function dnsPutter(): mixed { return self::$dnsPut; }
}
