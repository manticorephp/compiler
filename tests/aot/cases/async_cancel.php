<?php
// Cancellation is REAL, not a flag: a parked sibling is resumed with a
// CancelledException at its suspend point. Covers awaitAll's fail-fast, a
// throwing scope body (children must not be orphaned), and — the regression that
// motivated it — a CAUGHT failure no longer killing the program at exit.

use function Async\async;
use function Async\spawn;
use function Async\delay;
use function Async\awaitAll;
use Async\CancelledException;
use Async\TaskGroup;

final class Flag { public bool $hit = false; }

async(function () {
    // awaitAll: first failure rethrown as-is, siblings cancelled and joined.
    $slow = new Flag();
    $a = spawn(function () use ($slow) {
        try {
            delay(30.0);            // would outlive the program if not cancelled
        } catch (CancelledException $e) {
            $slow->hit = true;
            throw $e;
        }
        return 1;
    });
    $b = spawn(function () { delay(0.001); throw new \RuntimeException("bad"); });
    try {
        awaitAll($a, $b);
        echo "fail-fast: MISSING\n";
    } catch (\RuntimeException $e) {
        echo "fail-fast: ", $e->getMessage(), " cancelled: ", $slow->hit ? "yes" : "no", "\n";
    }

    // A scope whose BODY throws still cancels + joins what it spawned.
    $orphan = new Flag();
    try {
        TaskGroup::run(function (TaskGroup $g) use ($orphan) {
            $g->spawn(function () use ($orphan) {
                try {
                    delay(30.0);
                } catch (CancelledException $e) {
                    $orphan->hit = true;
                    throw $e;
                }
            });
            throw new \RuntimeException("body");
        });
        echo "scope-body: MISSING\n";
    } catch (\RuntimeException $e) {
        echo "scope-body: ", $e->getMessage(), " cancelled: ", $orphan->hit ? "yes" : "no", "\n";
    }

    // One child failing cancels its siblings and propagates out of the scope.
    $sib = new Flag();
    try {
        TaskGroup::run(function (TaskGroup $g) use ($sib) {
            $g->spawn(function () use ($sib) {
                try {
                    delay(30.0);
                } catch (CancelledException $e) {
                    $sib->hit = true;
                    throw $e;
                }
            });
            $g->spawn(function () { delay(0.001); throw new \RuntimeException("child"); });
        });
        echo "sibling: MISSING\n";
    } catch (\RuntimeException $e) {
        echo "sibling: ", $e->getMessage(), " cancelled: ", $sib->hit ? "yes" : "no", "\n";
    }

    echo "done\n";
});
// Reaching here at all is the point: a CAUGHT sibling failure must not be
// re-thrown out of async() at exit.
echo "exit clean\n";
