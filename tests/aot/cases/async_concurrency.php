<?php
// A bound on how many tasks run at once — the everyday "N at a time" — plus the
// scoped-value carrier every real server needs.

use function Async\async;
use function Async\delay;
use function Async\group;
use function Async\mapConcurrent;
use Async\Context;
use Async\Semaphore;
use Async\TaskGroup;

final class Gauge { public int $cur = 0; public int $max = 0; }

async(function () {
    // mapConcurrent never exceeds the limit, and keeps input order.
    $g1 = new Gauge();
    $out = mapConcurrent([1, 2, 3, 4, 5, 6, 7, 8], function (int $n) use ($g1) {
        $g1->cur = $g1->cur + 1;
        if ($g1->cur > $g1->max) { $g1->max = $g1->cur; }
        delay(0.01);
        $g1->cur = $g1->cur - 1;
        return $n * 2;
    }, 3);
    echo "map: ", \implode(",", $out), " max-inflight: ", $g1->max, "\n";

    // Fail-fast: the first failure cancels the rest and propagates.
    try {
        mapConcurrent([1, 2, 3], function (int $n) {
            if ($n === 2) { throw new \RuntimeException("boom"); }
            delay(0.05);
            return $n;
        }, 2);
        echo "map-fail: MISSING\n";
    } catch (\RuntimeException $e) {
        echo "map-fail: ", $e->getMessage(), "\n";
    }

    // The semaphore on its own.
    $g2 = new Gauge();
    $sem = new Semaphore(2);
    group(function (TaskGroup $grp) use ($sem, $g2) {
        for ($i = 0; $i < 6; $i++) {
            $grp->spawn(function () use ($sem, $g2) {
                $sem->withPermit(function () use ($g2) {
                    $g2->cur = $g2->cur + 1;
                    if ($g2->cur > $g2->max) { $g2->max = $g2->cur; }
                    delay(0.01);
                    $g2->cur = $g2->cur - 1;
                });
            });
        }
    });
    echo "semaphore max-inflight: ", $g2->max, " free-after: ", $sem->available(), "\n";

    // Scoped values: visible to every task inside the binding scope, nowhere else.
    $seen = Context::withValue("rid", "req-7", function () {
        return group(function (TaskGroup $grp) {
            $t = $grp->spawn(function () {
                // A grandchild scope still sees it through the parent chain.
                return group(fn() => Context::value("rid"));
            });
            return $t->await();
        });
    });
    echo "context: inside=", $seen, " outside=", Context::value("rid") === null ? "null" : "leaked", "\n";
    echo "done\n";
});
