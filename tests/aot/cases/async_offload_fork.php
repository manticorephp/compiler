<?php
// A child forked inside the loop must not consume the parent's pool completions.
// The parent's reader blocks a worker in open(2) on a FIFO; the child opens the
// write end (completing the job) and then waits in its own loop while the parent
// still holds the CPU. Linux shares the epoll instance across fork, so the child
// sees the parent's done pipe readable; it must leave the record to the parent.

use function Async\async;
use function Async\spawn;
use function Async\delay;

$fifo = sys_get_temp_dir() . '/mc_offload_fork_' . getmypid();
@unlink($fifo);
posix_mkfifo($fifo, 0600);

async(function () use ($fifo) {
    $c = Runtime\Libc\strdup($fifo);
    $reader = spawn(function () use ($c) {
        $fd = __mc_offload(17, ptr_to_int($c), 0, 0);
        Runtime\Libc\sys_close($fd);
        return $fd >= 0 ? "yes" : "no";
    });
    delay(0.05);
    $pid = pcntl_fork();
    if ($pid === 0) {
        $w = Runtime\Libc\sys_open($fifo, 1, 0);
        Runtime\Libc\sys_close($w);
        if (PHP_OS_FAMILY === 'Linux') {
            delay(0.3);
        }
        exit(0);
    }
    $end = hrtime(true) + 600000000;
    while (hrtime(true) < $end) {}
    echo "reader opened: ", $reader->await(), "\n";
    pcntl_waitpid($pid, $st);
    echo "child exit: ", pcntl_wexitstatus($st), "\n";
    Runtime\Libc\free($c);
});
unlink($fifo);
