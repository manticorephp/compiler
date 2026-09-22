<?php
// shutdownOn() registered BEFORE pcntl_fork() must not build (and PIN) an
// engine at registration time: it only calls pcntl_signal(); the closure
// resolves Scheduler::hasInstance()/instance() at DISPATCH time instead. A
// closure that captured the pre-fork (dead, idle) engine would still fire
// on SIGUSR1 but cancelRoot() on it is a no-op, so the child's delay(5.0)
// would run to completion instead of being cut short.
//
// Both outcomes exit 0, so the signal is whether the delay RAN OUT — reported
// as a marker file the child writes only if it reaches the far side, not as an
// elapsed-time threshold. A wall-clock assertion would have been the one thing
// a loaded machine could break; this one is deterministic, and a regression
// costs the suite five seconds rather than a flake.
// Manticore-only (Io\Poll has no php oracle) — difftest skips it.

use function Async\async;
use function Async\delay;
use function Async\shutdownOn;

$marker = rtrim(sys_get_temp_dir(), '/') . '/mc_shutdown_prefork_' . getmypid();
@unlink($marker);

shutdownOn(SIGUSR1);            // pre-fork: must be a no-op on the engine

$child = pcntl_fork();
if ($child === 0) {
    async(function () use ($marker) {
        delay(5.0);              // cut short by the inherited pre-fork handler
        file_put_contents($marker, 'ran-out');
    });
    exit(0);
}

usleep(200000);
posix_kill($child, SIGUSR1);
$status = 0;
pcntl_waitpid($child, $status);

echo "child exit: ", pcntl_wexitstatus($status), "\n";
echo "delay cut short: ", is_file($marker) ? "no" : "yes", "\n";
@unlink($marker);
