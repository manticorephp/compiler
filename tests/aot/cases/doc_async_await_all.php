<?php

use function Async\async;
use function Async\awaitAll;
use function Async\delay;
use function Async\spawn;

async(function () {
    // The tasks finish in the order c, b, a ...
    $a = spawn(function () { delay(0.03); return "a"; });
    $b = spawn(function () { delay(0.02); return "b"; });
    $c = spawn(function () { delay(0.01); return "c"; });

    // ... but results are keyed by input position.
    echo implode(",", awaitAll($a, $b, $c)), "\n";

    // Fail-fast: the first failure cancels the others and is rethrown.
    $ok = spawn(function () { delay(10.0); return "never"; });
    $bad = spawn(function () { delay(0.01); throw new RuntimeException("boom"); });
    try {
        awaitAll($ok, $bad);
    } catch (RuntimeException $e) {
        echo "failed: ", $e->getMessage(), "\n";
    }
    echo "slow task cancelled: ", $ok->isDone() ? "yes" : "no", "\n";
});
