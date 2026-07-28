<?php
// Deadlines and the cancellation handle: async() returning a value, group() as
// the everyday scope form, timeout() cancelling the WHOLE scope on expiry,
// deadline tightening through nesting, CancellationToken + onCancel, and
// Task::awaitWithin.

use function Async\async;
use function Async\spawn;
use function Async\delay;
use function Async\group;
use function Async\timeout;
use Async\CancelledException;
use Async\Context;
use Async\TaskGroup;
use Async\TimeoutException;

final class Flag { public bool $hit = false; public int $n = 0; }

// async() composes like an ordinary call.
$r = async(function () {
    $a = spawn(fn() => 20);
    $b = spawn(fn() => 22);
    return $a->await() + $b->await();
});
echo "async returns: ", $r, "\n";

async(function () {
    echo "group: ", group(function (TaskGroup $g) {
        $x = $g->spawn(fn() => 3);
        $y = $g->spawn(fn() => 4);
        return $x->await() + $y->await();
    }), "\n";

    echo "timeout ok: ", timeout(1.0, function () { delay(0.01); return "fast"; }), "\n";

    // Expiry cancels the body AND everything it spawned, then throws.
    $f = new Flag();
    try {
        timeout(0.05, function (TaskGroup $g) use ($f) {
            $g->spawn(function () use ($f) {
                try {
                    delay(30.0);
                } catch (CancelledException $e) {
                    $f->hit = true;
                    throw $e;
                }
            });
            delay(30.0);
            return "never";
        });
        echo "timeout expiry: MISSING\n";
    } catch (TimeoutException $e) {
        echo "timeout expiry: caught, child-cancelled: ", $f->hit ? "yes" : "no", "\n";
    }

    // A nested deadline can only TIGHTEN the enclosing one.
    timeout(10.0, function () {
        $outer = Context::remaining();
        timeout(0.5, function () use ($outer) {
            echo "deadline tightens: ", (Context::remaining() < $outer) ? "yes" : "no", "\n";
            return null;
        });
        return null;
    });
    echo "no deadline outside: ", Context::remaining() === null ? "null" : "set", "\n";

    // The token is the read-only half; the scope is the write half.
    $f2 = new Flag();
    group(function (TaskGroup $g) use ($f2) {
        $tok = $g->token();
        $tok->onCancel(function () use ($f2) { $f2->n = $f2->n + 1; });
        $g->spawn(function () { delay(30.0); });
        delay(0.01);
        echo "token before: ", $tok->isCancelled() ? "yes" : "no", "\n";
        $g->cancel();
        echo "token after: ", $tok->isCancelled() ? "yes" : "no", "\n";
    });
    echo "onCancel fired: ", $f2->n, "\n";

    // awaitWithin cancels the task it gave up on.
    $slow = spawn(function () { delay(30.0); return 1; });
    try {
        $slow->awaitWithin(0.05);
        echo "awaitWithin: MISSING\n";
    } catch (TimeoutException $e) {
        echo "awaitWithin: timed out, task settled: ", $slow->isDone() ? "yes" : "no", "\n";
    }
    echo "done\n";
});
echo "exit clean\n";
