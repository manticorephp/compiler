<?php
// Cancellation must be STICKY, must be shieldable, and must not be swallowed by
// a scope. Three defects the first cut of the runtime had, each observable.

use function Async\async;
use function Async\spawn;
use function Async\delay;
use function Async\group;
use function Async\shield;
use function Async\timeout;
use Async\CancelledException;
use Async\TaskGroup;
use Async\TimeoutException;

final class Flag { public bool $hit = false; public int $n = 0; }

async(function () {
    // STICKY: a blanket catch buys the task exactly one more suspend point, not
    // immunity. With a one-shot throw the second delay() slept its full 10s and
    // the 0.05s timeout was reported ~10s late.
    $f = new Flag();
    $t0 = \microtime(true);
    try {
        timeout(0.05, function (TaskGroup $g) use ($f) {
            $g->spawn(function () use ($f) {
                try { delay(10.0); } catch (\Throwable $e) { $f->n = $f->n + 1; }
                try { delay(10.0); } catch (\Throwable $e) { $f->n = $f->n + 1; }
                return 1;
            });
            delay(10.0);
            return null;
        });
        echo "sticky: MISSING\n";
    } catch (TimeoutException $e) {
        $elapsed = \microtime(true) - $t0;
        echo "sticky: raised=", $f->n, " prompt=", $elapsed < 1.0 ? "yes" : "no", "\n";
    }

    // catch (\Exception) must NOT see a cancellation at all — it is an \Error.
    $f2 = new Flag();
    group(function (TaskGroup $g) use ($f2) {
        $g->spawn(function () use ($f2) {
            try {
                delay(10.0);
            } catch (\Exception $e) {
                $f2->hit = true;          // must not fire
            } catch (CancelledException $e) {
                $f2->n = 1;
            }
        });
        delay(0.01);
        $g->cancel();
    });
    echo "not-an-exception: caught-by-Exception=", $f2->hit ? "yes" : "no", " by-Cancelled=", $f2->n, "\n";

    // SHIELD: cleanup that must itself suspend still gets to run.
    $f3 = new Flag();
    group(function (TaskGroup $g) use ($f3) {
        $g->spawn(function () use ($f3) {
            try {
                delay(10.0);
            } catch (CancelledException $e) {
                shield(function () use ($f3) {
                    delay(0.01);          // a suspend INSIDE cleanup
                    $f3->n = 1;
                });
                $f3->hit = true;
                throw $e;
            }
        });
        delay(0.01);
        $g->cancel();
    });
    echo "shield: cleanup-ran=", $f3->n, " completed=", $f3->hit ? "yes" : "no", "\n";

    // A scope must not SWALLOW cancellation: group() used to record Cancelled as
    // "not a failure" and return null, so the cancelled task carried on.
    $f4 = new Flag();
    group(function (TaskGroup $g) use ($f4) {
        $g->spawn(function () use ($f4) {
            try {
                group(function (TaskGroup $inner) {
                    delay(10.0);
                    return "not reached";
                });
                $f4->hit = true;          // must not be reached
            } catch (CancelledException $e) {
                $f4->n = 1;
            }
        });
        delay(0.01);
        $g->cancel();
    });
    echo "group-unwinds: ran-past-scope=", $f4->hit ? "yes" : "no", " caught=", $f4->n, "\n";
    echo "done\n";
});
