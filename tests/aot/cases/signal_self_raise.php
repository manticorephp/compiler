<?php

// A signal is captured through a descriptor (kqueue EVFILT_SIGNAL / signalfd),
// never in signal context, and the PHP handler runs at the dispatch point.
// SIGUSR1 (30 on Darwin, 10 on Linux) is chosen through the same PHP_OS test
// the runtime uses, so this case is portable.

$SIGUSR1 = PHP_OS_FAMILY === "Darwin" ? 30 : 10;

pcntl_signal($SIGUSR1, function (int $signo) use ($SIGUSR1): void {
    echo "handled ", $signo === $SIGUSR1 ? "usr1" : (string)$signo, "\n";
});

echo "before\n";
// Nothing pending yet — dispatch must be a quiet no-op.
pcntl_signal_dispatch();
echo "no-pending-ok\n";

posix_kill(posix_getpid(), $SIGUSR1);
pcntl_signal_dispatch();

echo "after\n";
