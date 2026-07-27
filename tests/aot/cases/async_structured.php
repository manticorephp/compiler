<?php
// Structured concurrency core: spawn/await, a TaskGroup scope, awaitAll in input
// order, awaitAny's first success and its all-failed AggregateError.
// Manticore-only (Io\Poll has no php oracle) — difftest skips it.

use function Async\async;
use function Async\spawn;
use function Async\delay;
use function Async\awaitAll;
use function Async\awaitAny;
use Async\AggregateError;
use Async\TaskGroup;

async(function () {
    $a = spawn(function () { delay(0.02); return 10; });
    $b = spawn(function () { delay(0.01); return 32; });
    echo "sum: ", $a->await() + $b->await(), "\n";

    $product = TaskGroup::run(function (TaskGroup $g) {
        $x = $g->spawn(fn() => 3);
        $y = $g->spawn(fn() => 4);
        return $x->await() * $y->await();
    });
    echo "group: ", $product, "\n";

    // Completion order is rb, rc, ra — the results must still come back keyed
    // by INPUT position.
    $ra = spawn(function () { delay(0.005); return 1; });
    $rb = spawn(function () { delay(0.001); return 2; });
    $rc = spawn(function () { delay(0.003); return 3; });
    $all = awaitAll($ra, $rb, $rc);
    echo "all: ", $all[0], ",", $all[1], ",", $all[2], "\n";
    echo "all-empty: ", \count(awaitAll()), "\n";

    // A failure before the winner is ignored while a candidate remains.
    $fail = spawn(function () { delay(0.001); throw new \RuntimeException("boom"); });
    $win = spawn(function () { delay(0.004); return "ok"; });
    echo "any: ", awaitAny($fail, $win), "\n";

    // Every input failed → AggregateError keyed by input position.
    $f1 = spawn(function () { throw new \RuntimeException("e1"); });
    $f2 = spawn(function () { throw new \RuntimeException("e2"); });
    try {
        awaitAny($f1, $f2);
        echo "any-all-fail: MISSING\n";
    } catch (AggregateError $e) {
        $errs = $e->errors();
        echo "any-all-fail: ", $errs[0]->getMessage(), ",", $errs[1]->getMessage(), "\n";
        echo "previous: ", $e->getPrevious()->getMessage(), "\n";
    }
    echo "done\n";
});
