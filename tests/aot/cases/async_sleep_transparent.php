<?php

// sleep()/usleep()/time_nanosleep() must suspend the FIBER under a scheduler, not
// the process: three tasks sleeping 60 ms each finish in ~60 ms, not 180 ms. Any
// third-party retry/backoff loop is written with these, so a blocking one froze
// every other task.

use function Async\async;
use function Async\spawn;
use function Async\awaitAll;

$t0 = microtime(true);

$order = async(function (): array {
    $log = [];
    $a = spawn(function () use (&$log): string {
        usleep(60000);
        return 'a';
    });
    $b = spawn(function () use (&$log): string {
        usleep(20000);
        return 'b';
    });
    $c = spawn(function () use (&$log): string {
        time_nanosleep(0, 40000000);
        return 'c';
    });
    /** @var string[] $res */
    $res = awaitAll($a, $b, $c);
    return $res;
});

$elapsed = microtime(true) - $t0;

echo "results: ", implode(',', $order), "\n";
echo "overlapped: ", ($elapsed < 0.15 ? 'yes' : 'no'), "\n";

// The completion ORDER proves interleaving: the shortest sleep returns first.
// (A string accumulator, not an array: appending a literal into an array captured
// by reference is the erased-element repr bug tracked in the array-api epic.)
$seq = async(function (): string {
    $done = '';
    $slow = spawn(function () use (&$done): void { usleep(60000); $done = $done . 'slow,'; });
    $fast = spawn(function () use (&$done): void { usleep(10000); $done = $done . 'fast,'; });
    $mid  = spawn(function () use (&$done): void { usleep(30000); $done = $done . 'mid,'; });
    $slow->await();
    $fast->await();
    $mid->await();
    return $done;
});
echo "order: ", $seq, "\n";

// Outside a scheduler the same call is an ordinary blocking sleep.
$t1 = microtime(true);
usleep(30000);
echo "sync still sleeps: ", ((microtime(true) - $t1) >= 0.02 ? 'yes' : 'no'), "\n";

// A whole-second sleep() is the same seam (kept short: 1 s is the smallest unit).
$w = async(function (): string {
    $s = spawn(function (): string { sleep(1); return 'slept'; });
    $f = spawn(function (): string { usleep(1000); return 'quick'; });
    $r = $f->await();
    return $r . '+' . $s->await();
});
echo "sleep(): ", $w, "\n";
