<?php

// Signal delivery is REACTOR-NATIVE: kqueue's EVFILT_SIGNAL on Darwin, signalfd(2)
// on Linux. The pump used to be a task calling pcntl_signal_dispatch() every 50 ms,
// which bounded handler latency at 50 ms and woke the loop 20×/s forever, even in a
// process with nothing else to do.
//
// The assertion is the MECHANISM, not the clock: over 300 ms the only timers that
// fire are the test's own two delays. A 50 ms ticker would add six more.

use function Async\async;
use function Async\spawn;

$out = async(function (): string {
    $seen = new stdClass();
    $seen->hit = false;
    pcntl_signal(SIGUSR1, function () use ($seen) { $seen->hit = true; });

    $before = Async\stats()['timer_fires'];
    spawn(function (): void { Async\delay(0.02); posix_kill(posix_getpid(), SIGUSR1); });
    Async\delay(0.30);
    $fires = Async\stats()['timer_fires'] - $before;

    return 'handled=' . ($seen->hit ? 'yes' : 'NO')
        . ' idle=' . ($fires <= 3 ? 'yes' : 'no(' . (string)$fires . ')');
});
echo $out, "\n";
