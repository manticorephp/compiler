<?php

// MANTICORE-ONLY: `Process\supervise` is superset API, so the expected output is
// written BY HAND.
//
// A worker that dies the instant it starts — a port that went away, a config the
// child rejects — used to be re-forked the moment it was reaped, so the
// supervisor span at fork speed: 23 restarts in 1.2 s here, bounded only by the
// reap loop's own 50 ms sleep. With the backoff (0.1 s doubling to a 5 s
// ceiling) the same window allows 4.
//
// The threshold below is one-sided on purpose: a LOADED machine can only make
// the backoff allow FEWER restarts, never more, so this cannot flake into a
// failure — and 12 sits well clear of both 4 and 23.

$marks = rtrim(sys_get_temp_dir(), '/') . '/mc_supervise_backoff_' . getmypid();
@unlink($marks);

$sup = pcntl_fork();
if ($sup === 0) {
    \Process\supervise(1, function (int $i) use ($marks): void {
        file_put_contents($marks, 'x', FILE_APPEND);
        exit(3);
    });
    exit(0);
}

usleep(1200000);
posix_kill($sup, SIGTERM);
$status = 0;
pcntl_waitpid($sup, $status);

$n = is_file($marks) ? strlen(file_get_contents($marks)) : 0;
@unlink($marks);

echo 'restarted at least once: ', $n >= 1 ? 'yes' : 'no', "\n";
echo 'restart rate bounded: ', $n < 12 ? 'yes' : 'no', "\n";
echo "supervisor exited\n";
