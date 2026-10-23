<?php

use Async\Mutex;
use function Async\async;
use function Async\delay;
use function Async\spawn;

async(function () {
    $mu = new Mutex();

    // Each section suspends in the middle. Without the lock the two tasks
    // would interleave their steps.
    $work = function (string $name) use ($mu) {
        $mu->withLock(function () use ($name) {
            echo "$name: start\n";
            delay(0.01);
            echo "$name: end\n";
        });
    };

    $a = spawn(fn() => $work("A"));
    $b = spawn(fn() => $work("B"));
    $a->await();
    $b->await();

    // tryLock() never waits.
    $mu->lock();
    var_dump($mu->tryLock());
    $mu->unlock();
    var_dump($mu->isLocked());
});
