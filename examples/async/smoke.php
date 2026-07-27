<?php

// Core: fibers + scheduler + structured scope, no I/O. Proves spawn/await/delay/
// TaskGroup/cancellation compose on the compiler before touching sockets.

use function Async\async;
use function Async\spawn;
use function Async\delay;
use function Async\awaitAll;
use function Async\awaitAny;
use Async\AggregateError;
use Async\CancelledException;
use Async\TaskGroup;

/** Shared observable — closures capture by value, so state needs an object. */
final class Flag
{
    public bool $hit = false;
}

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

    // awaitAll — every result in INPUT order, regardless of completion order.
    $ra = spawn(function () { delay(0.005); return 1; });
    $rb = spawn(function () { delay(0.001); return 2; });
    $rc = spawn(function () { delay(0.003); return 3; });
    $all = awaitAll($ra, $rb, $rc);
    echo "all: ", $all[0], ",", $all[1], ",", $all[2], "\n";

    // awaitAny — first success wins, failures ignored while a candidate remains.
    $fail = spawn(function () { delay(0.001); throw new \RuntimeException("boom"); });
    $winner = spawn(function () { delay(0.002); return "ok"; });
    echo "any: ", awaitAny($fail, $winner), "\n";

    // awaitAny — every task fails → AggregateError keyed by INPUT position.
    $f1 = spawn(function () { throw new \RuntimeException("e1"); });
    $f2 = spawn(function () { throw new \RuntimeException("e2"); });
    try {
        awaitAny($f1, $f2);
        echo "any-all-fail: MISSING\n";
    } catch (AggregateError $e) {
        $errs = $e->errors();
        echo "any-all-fail: ", \count($errs), " ", $errs[0]->getMessage(), ",", $errs[1]->getMessage(), "\n";
    }

    // awaitAll is FAIL-FAST: the first failure is rethrown as-is, and the
    // siblings still running are CANCELLED (not left to finish unobserved).
    $slowFlag = new Flag();
    $g1 = spawn(function () use ($slowFlag) {
        try {
            delay(5.0);            // would outlive the whole program if not cancelled
        } catch (CancelledException $e) {
            $slowFlag->hit = true;
            throw $e;
        }
        return 7;
    });
    $g2 = spawn(function () { delay(0.001); throw new \RuntimeException("bad"); });
    try {
        awaitAll($g1, $g2);
        echo "all-fail: MISSING\n";
    } catch (\RuntimeException $e) {
        echo "all-fail: ", $e->getMessage(), " sibling-cancelled: ", $slowFlag->hit ? "yes" : "no", "\n";
    }

    // A scope whose BODY throws must still cancel + join the children it already
    // spawned — no orphan outliving the scope, no swallowed failure.
    $orphanFlag = new Flag();
    try {
        TaskGroup::run(function (TaskGroup $g) use ($orphanFlag) {
            $g->spawn(function () use ($orphanFlag) {
                try {
                    delay(5.0);
                } catch (CancelledException $e) {
                    $orphanFlag->hit = true;
                    throw $e;
                }
            });
            throw new \RuntimeException("body");
        });
        echo "group-body: MISSING\n";
    } catch (\RuntimeException $e) {
        echo "group-body: ", $e->getMessage(), " child-cancelled: ", $orphanFlag->hit ? "yes" : "no", "\n";
    }

    // Two tasks each opening their OWN nested scope, interleaved. The scope must
    // live on the task, not on a scheduler-global stack, or B's spawn lands in
    // A's group and each join waits for the other's child.
    $mk = function (string $tag, float $d) {
        return function () use ($tag, $d) {
            return TaskGroup::run(function (TaskGroup $g) use ($tag, $d) {
                $c = $g->spawn(function () use ($d) { delay($d); return 1; });
                return $tag . ":" . $c->await();
            });
        };
    };
    $p = spawn($mk("A", 0.03));
    $q = spawn($mk("B", 0.01));
    echo "nested: ", $p->await(), " ", $q->await(), "\n";

    echo "done\n";
});
