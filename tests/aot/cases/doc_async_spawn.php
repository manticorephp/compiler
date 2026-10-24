<?php

use function Async\async;
use function Async\delay;
use function Async\spawn;

// async() runs the callable on the engine and returns its value.
$total = async(function () {
    // spawn() starts a task in the current scope and returns a handle.
    $slow = spawn(function () {
        delay(0.02);
        return 10;
    });
    $fast = spawn(function () {
        delay(0.01);
        return 32;
    });

    // await() suspends this task (not the process) until the other one settles.
    return $slow->await() + $fast->await();
});

echo "total: $total\n";
