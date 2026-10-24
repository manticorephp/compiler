<?php

use function Async\async;
use function Async\awaitAllSettled;
use function Async\delay;
use function Async\mapSettled;
use function Async\spawn;

async(function () {
    $a = spawn(function () { delay(0.01); return "fine"; });
    $b = spawn(function () { throw new RuntimeException("broken"); });

    // Nothing is cancelled and nothing is thrown: every outcome is reported.
    foreach (awaitAllSettled($a, $b) as $i => $s) {
        echo "$i: ", $s->ok ? "ok {$s->value}" : "error {$s->error->getMessage()}", "\n";
    }

    $results = mapSettled([2, 0, 4], function (int $n) {
        if ($n === 0) {
            throw new InvalidArgumentException("zero");
        }
        return 8 / $n;
    }, 2);
    foreach ($results as $i => $s) {
        echo "$i: ", $s->ok ? "ok {$s->value}" : "error {$s->error->getMessage()}", "\n";
    }
});
