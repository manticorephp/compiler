<?php
// Workers mask every signal: a handled signal still reaches the main thread's
// dispatch while pool threads exist.
use function Async\async;
use function Async\delay;
async(function () {
    __mc_offload(0, 1);                     // pool is up
    pcntl_signal(SIGUSR1, function () { echo "usr1 handled\n"; });
    posix_kill(getmypid(), SIGUSR1);
    delay(0.1);
    echo "after\n";
});
