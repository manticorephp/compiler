<?php

use Async\Semaphore;
use function Async\async;
use function Async\awaitAll;
use function Async\delay;
use function Async\spawn;

async(function () {
    // At most two of the five tasks hold a permit at any moment.
    $sem = new Semaphore(2);
    $active = 0;
    $peak = 0;

    $tasks = [];
    for ($i = 1; $i <= 5; $i++) {
        $tasks[] = spawn(function () use ($sem, &$active, &$peak, $i) {
            $sem->withPermit(function () use (&$active, &$peak) {
                $active++;
                $peak = max($peak, $active);
                delay(0.01);
                $active--;
            });
            return $i;
        });
    }

    echo implode(",", awaitAll(...$tasks)), "\n";
    echo "peak: $peak, free: ", $sem->available(), "\n";
});
