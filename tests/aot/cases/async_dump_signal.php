<?php

// `Async\dumpOn(SIGQUIT)` — Go's SIGQUIT-dumps-every-goroutine: interrogate a
// process that is ALREADY hung, without restarting it. The report goes to STDERR
// (not compared by the harness); what IS asserted here is that the signal is
// handled at all — SIGQUIT's default disposition would kill the process, so
// reaching the final echo is the assertion.

use function Async\async;
use function Async\delay;
use function Async\spawn;

$out = async(function (): string {
    Async\dumpOn(SIGQUIT);
    spawn(function (): int { delay(0.2); return 1; })->named('parked');
    posix_kill(posix_getpid(), SIGQUIT);
    delay(0.05);                      // let the pump reap and run the handler
    $s = Async\stats();
    return 'live=' . (string)$s['live'] . ' timers=' . (string)$s['timers'];
});
echo $out, "\n";
echo "survived\n";
