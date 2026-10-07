<?php
// Pool size: default 4, MANTICORE_BLOCKING_THREADS clamps to 1..64, junk → 4.
use function Async\async;
foreach (['', '2', '0', '1000', 'junk'] as $v) {
    putenv($v === '' ? 'MANTICORE_BLOCKING_THREADS' : "MANTICORE_BLOCKING_THREADS=$v");
    $pid = pcntl_fork();
    if ($pid === 0) {
        async(function () {
            __mc_offload(0, 1);
            $s = Async\stats();
            echo "threads=", $s['pool_threads'], " offloaded>0=", $s['offloaded'] > 0 ? 1 : 0, "\n";
        });
        exit(0);
    }
    pcntl_waitpid($pid, $st);
}
