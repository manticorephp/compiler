<?php
// A task cancelled while its job is in flight is not resumed until the job
// completes; it then settles as cancelled. The loop keeps running meanwhile.

use function Async\async;
use function Async\spawn;
use function Async\delay;

$fifo = sys_get_temp_dir() . '/mc_offload_cancel_' . getmypid();
@unlink($fifo);
posix_mkfifo($fifo, 0600);

// open(2) through the pool directly (O_RDONLY = 0, O_WRONLY = 1 on both hosts).
function pool_open(string $path, int $flags): int {
    $c = Runtime\Libc\strdup($path);
    $fd = __mc_offload(17, ptr_to_int($c), $flags, 0);
    Runtime\Libc\free($c);
    return $fd;
}

async(function () use ($fifo) {
    $t = spawn(function () use ($fifo) {
        $fd = pool_open($fifo, 0);        // blocks a worker until the writer opens
        echo "opened: ", $fd >= 0 ? "yes" : "no", "\n";
        Runtime\Libc\sys_close($fd);
        Async\delay(1.0);                 // first suspend after the job: cancellation lands here
        echo "not reached\n";
    });
    delay(0.05);
    $t->cancel();
    echo "cancel requested\n";
    delay(0.05);
    echo "ticked while parked\n";
    $w = spawn(function () use ($fifo) {
        $fd = pool_open($fifo, 1);
        Runtime\Libc\sys_close($fd);
    });
    $w->await();
    $s = $t->join();
    echo "settled: ", $s->error instanceof Async\CancelledException ? "cancelled" : "other", "\n";
});
unlink($fifo);
