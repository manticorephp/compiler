<?php

// Core spike: fibers + scheduler + structured scope, no I/O. Proves spawn/await/
// delay/TaskGroup compose on the compiler before touching sockets.

use function Async\run;
use function Async\spawn;
use function Async\delay;
use Async\TaskGroup;

run(function () {
    echo "start\n";

    // Two concurrent tasks with staggered delays; await both.
    $a = spawn(function () {
        delay(0.02);
        return 10;
    });
    $b = spawn(function () {
        delay(0.01);
        return 32;
    });
    echo "sum: ", $a->await() + $b->await(), "\n";

    // A structured scope — run() returns only once both children are done.
    $product = TaskGroup::run(function (TaskGroup $g) {
        $x = $g->spawn(fn() => 3);
        $y = $g->spawn(fn() => 4);
        return $x->await() * $y->await();
    });
    echo "group: ", $product, "\n";

    echo "done\n";
});
