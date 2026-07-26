<?php
// Synchronisation primitives. NOTE what is NOT here: atomics. The scheduler is
// cooperative and single-threaded, so a read-modify-write between two suspend
// points cannot be torn. Mutex exists for the other problem — a critical section
// that SUSPENDS in the middle.

use function Async\async;
use function Async\delay;
use function Async\group;
use function Async\spawn;
use Async\CancelledException;
use Async\Mutex;
use Async\Once;
use Async\TaskGroup;

final class Log { public string $s = ""; public int $n = 0; public bool $hit = false; }

async(function () {
    // Without the lock the two tasks interleave between the suspends and the log
    // reads "aAbB"; with it each critical section is whole.
    $log = new Log();
    $mu = new Mutex();
    group(function (TaskGroup $g) use ($mu, $log) {
        $g->spawn(function () use ($mu, $log) {
            $mu->withLock(function () use ($log) {
                $log->s = $log->s . "a";
                delay(0.02);                    // suspends INSIDE the section
                $log->s = $log->s . "A";
            });
        });
        $g->spawn(function () use ($mu, $log) {
            delay(0.005);                        // arrive while the first holds it
            $mu->withLock(function () use ($log) {
                $log->s = $log->s . "b";
                delay(0.01);
                $log->s = $log->s . "B";
            });
        });
    });
    echo "mutex: ", $log->s, "\n";

    // tryLock never suspends; a self-lock is a bug, not a hang.
    $mu2 = new Mutex();
    echo "tryLock free: ", $mu2->tryLock() ? "yes" : "no", "\n";
    echo "tryLock held: ", $mu2->tryLock() ? "yes" : "no", "\n";
    try {
        $mu2->lock();
        echo "reentrant: MISSING\n";
    } catch (\LogicException $e) {
        echo "reentrant: ", $e->getMessage(), "\n";
    }
    $mu2->unlock();
    echo "unlocked: ", $mu2->isLocked() ? "still" : "free", "\n";

    // Once: the initialiser suspends, so without it every contender builds one.
    $log2 = new Log();
    $once = new Once();
    $vals = group(function (TaskGroup $g) use ($once, $log2) {
        /** @var Async\Task[] $ts */
        $ts = [];
        for ($i = 0; $i < 4; $i++) {
            $ts[$i] = $g->spawn(function () use ($once, $log2) {
                return $once->run(function () use ($log2) {
                    $log2->n = $log2->n + 1;
                    delay(0.01);
                    return "built";
                });
            });
        }
        $out = "";
        foreach ($ts as $t) { $out = $out . $t->await(); }
        return $out;
    });
    echo "once: built ", $log2->n, " time(s), all got ", $vals, "\n";
    echo "once hasRun: ", $once->hasRun() ? "yes" : "no", "\n";

    // A cancelled initialiser is not a result — the next caller retries.
    $log3 = new Log();
    $once2 = new Once();
    group(function (TaskGroup $g) use ($once2, $log3) {
        $t = $g->spawn(function () use ($once2, $log3) {
            $once2->run(function () use ($log3) {
                $log3->n = $log3->n + 1;
                delay(10.0);
                return "never";
            });
        });
        delay(0.01);
        $t->cancel();                            // Task::cancel(), no Scheduler poking
        $r = $t->join();                         // join(), not await(): we are not the one being cancelled
        echo "cancelled task: ok=", $r->ok ? "yes" : "no", " err=", $r->error === null ? "none" : \get_class($r->error), "\n";
    });
    echo "once after cancel: hasRun=", $once2->hasRun() ? "yes" : "no", "\n";
    echo "retry: ", $once2->run(fn() => "second"), " attempts=", $log3->n, "\n";
    echo "done\n";
});
