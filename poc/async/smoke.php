<?php

// Core spike: fibers + scheduler + structured scope, no I/O. Proves spawn/await/
// delay/TaskGroup compose on the compiler before touching sockets.

use function Async\async;
use function Async\spawn;
use function Async\delay;
use function Async\awaitAll;
use function Async\awaitAny;
use Async\AggregateError;
use Async\TaskGroup;

async(function () {
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

    // awaitAll — collect every result in input order.
    $ra = spawn(function () { delay(0.005); return 1; });
    $rb = spawn(function () { delay(0.001); return 2; });
    $rc = spawn(function () { delay(0.003); return 3; });
    $all = awaitAll($ra, $rb, $rc);
    echo "all: ", $all[0], ",", $all[1], ",", $all[2], "\n";

    // awaitAny — first success wins, failures are ignored while a candidate remains.
    $fail = spawn(function () { delay(0.001); throw new \RuntimeException("boom"); });
    $winner = spawn(function () { delay(0.002); return "ok"; });
    $slow = spawn(function () { delay(0.050); return "late"; });
    echo "any: ", awaitAny($fail, $winner, $slow), "\n";

    // awaitAny — every task fails → AggregateError.
    $f1 = spawn(function () { throw new \RuntimeException("e1"); });
    $f2 = spawn(function () { throw new \RuntimeException("e2"); });
    try {
        awaitAny($f1, $f2);
        echo "any-all-fail: MISSING\n";
    } catch (AggregateError $e) {
        echo "any-all-fail: ", \count($e->errors()), "\n";
    }

    // awaitAll — one failure → AggregateError carrying every failure.
    $g1 = spawn(function () { return 7; });
    $g2 = spawn(function () { throw new \RuntimeException("bad"); });
    try {
        awaitAll($g1, $g2);
        echo "all-fail: MISSING\n";
    } catch (AggregateError $e) {
        echo "all-fail: ", \count($e->errors()), "\n";
    }

    echo "done\n";
});

