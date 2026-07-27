<?php
// ext/pcntl signals. A C handler cannot be a PHP closure, so the signal is
// BLOCKED (its default action cannot fire, the kernel holds it pending) and
// collected at pcntl_signal_dispatch() — php's own deferred model, so this file
// runs identically under the interpreter.

final class Sink
{
    public string $seen = "";
    public int $n = 0;
}

$sink = new Sink();

// Host-divergent numbers, resolved at compile time from the build host's
// <signal.h>: SIGTERM/SIGINT agree everywhere, SIGUSR1 does not (30 vs 10).
var_dump(SIGTERM === 15, SIGINT === 2, SIGHUP === 1);
var_dump(SIGUSR1 > 0, SIGUSR2 > 0, SIGUSR1 !== SIGUSR2);

pcntl_signal(SIGTERM, function (int $s) use ($sink) {
    $sink->seen = $sink->seen . "T";
    $sink->n = $sink->n + 1;
});
pcntl_signal(SIGUSR1, function (int $s) use ($sink) {
    $sink->seen = $sink->seen . "U";
    $sink->n = $sink->n + 1;
});

$me = posix_getpid();
var_dump($me > 0);
var_dump(posix_getppid() > 0);

// Deferred: raising does NOT run the handler.
posix_kill($me, SIGTERM);
var_dump($sink->n);

pcntl_signal_dispatch();
var_dump($sink->n, $sink->seen);

// A second signal, and a second dispatch.
posix_kill($me, SIGUSR1);
pcntl_signal_dispatch();
var_dump($sink->n, $sink->seen);

// Nothing pending — dispatch is a no-op.
pcntl_signal_dispatch();
var_dump($sink->n);

// A handler can be replaced, and handed back to the OS.
pcntl_signal(SIGTERM, function (int $s) use ($sink) { $sink->seen = $sink->seen . "t"; });
posix_kill($me, SIGTERM);
pcntl_signal_dispatch();
var_dump($sink->seen);

pcntl_signal(SIGTERM, SIG_IGN);
posix_kill($me, SIGTERM);          // ignored by the OS now, never queued
pcntl_signal_dispatch();
var_dump($sink->seen);

// Delivering signal 0 only probes that the process exists.
var_dump(posix_kill($me, 0));
var_dump(posix_kill(999999, 0));
echo "done\n";
