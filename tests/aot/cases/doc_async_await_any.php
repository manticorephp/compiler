<?php

use Async\AggregateError;
use function Async\async;
use function Async\awaitAny;
use function Async\delay;
use function Async\spawn;

async(function () {
    // A failure is ignored while another task can still succeed.
    $fails = spawn(function () { delay(0.01); throw new RuntimeException("mirror 1 down"); });
    $wins = spawn(function () { delay(0.02); return "mirror 2"; });
    echo awaitAny($fails, $wins), "\n";

    // Only when every task failed does awaitAny() throw.
    $x = spawn(function () { throw new RuntimeException("first"); });
    $y = spawn(function () { delay(0.01); throw new RuntimeException("second"); });
    try {
        awaitAny($x, $y);
    } catch (AggregateError $e) {
        foreach ($e->errors() as $i => $err) {
            echo "task $i: ", $err->getMessage(), "\n";
        }
    }
});
