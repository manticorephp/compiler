<?php
// Signals inside the scheduler: a handler is ordinary PHP at a safe point, and
// shutdownOn() turns SIGTERM into a clean unwind of the whole root scope.
// Manticore-only (Io\Poll has no php oracle) — difftest skips it.

use function Async\async;
use function Async\delay;
use function Async\shield;
use function Async\shutdownOn;
use function Async\spawn;
use Async\CancelledException;

final class Trace
{
    public string $log = "";
    public int $ticks = 0;
    public bool $drained = false;
}

$t = new Trace();

// A signal handler may do things a real one never could — allocate, spawn,
// suspend — because it runs at a dispatch point, not in signal context.
$out = async(function () use ($t) {
    pcntl_signal(SIGUSR1, function (int $s) use ($t) {
        $t->log = $t->log . "usr1(" . ($s === SIGUSR1 ? "ok" : "bad") . ")";
        spawn(function () use ($t) { delay(0.01); $t->log = $t->log . "+spawned"; });
    });
    posix_kill(posix_getpid(), SIGUSR1);
    delay(0.05);                                  // let the loop dispatch + run it
    return "main-done";
});
echo "handler: ", $t->log, "\n";
echo "async returned: ", $out, "\n";

// shutdownOn: a server that never ends on its own, stopped by a signal.
$out2 = async(function () use ($t) {
    shutdownOn(SIGTERM, SIGINT);
    $server = spawn(function () use ($t) {
        try {
            while (true) { $t->ticks = $t->ticks + 1; delay(0.01); }
        } catch (CancelledException $e) {
            shield(function () use ($t) { delay(0.005); $t->drained = true; });
            throw $e;
        }
    });
    spawn(function () { delay(0.12); posix_kill(posix_getpid(), SIGTERM); });
    $server->join();
    return "never-reached";
});
echo "shutdown: ticked=", $t->ticks > 3 ? "yes" : "no",
     " drained=", $t->drained ? "yes" : "no",
     " returned=", $out2 === null ? "null" : $out2, "\n";
echo "done\n";
