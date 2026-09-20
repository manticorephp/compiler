<?php
// shutdownOn() registered BEFORE pcntl_fork() must not build (and PIN) an
// engine at registration time: it only calls pcntl_signal(); the closure
// resolves Scheduler::hasInstance()/instance() at DISPATCH time instead. A
// closure that captured the pre-fork (dead, idle) engine would still fire
// on SIGUSR1 but cancelRoot() on it is a no-op, so the child's delay(5.0)
// would run to completion instead of being cut short — same "child exit: 0",
// ~5s slower, so timing (not just the exit code) is the signal here.
// Manticore-only (Io\Poll has no php oracle) — difftest skips it.

use function Async\async;
use function Async\delay;
use function Async\shutdownOn;

shutdownOn(SIGUSR1);            // pre-fork: must be a no-op on the engine

$child = pcntl_fork();
if ($child === 0) {
    async(function () {
        delay(5.0);              // cut short by the inherited pre-fork handler
    });
    exit(0);
}

$start = microtime(true);
usleep(200000);
posix_kill($child, SIGUSR1);
pcntl_waitpid($child, $status);
$elapsed = microtime(true) - $start;
echo "child exit: ", pcntl_wexitstatus($status), "\n";
echo "fast: ", $elapsed < 2.0 ? "yes" : "no", "\n";
