<?php

use Async\Once;
use function Async\async;
use function Async\awaitAll;
use function Async\delay;
use function Async\spawn;

async(function () {
    $once = new Once();
    $built = 0;

    $get = function () use ($once, &$built) {
        // The initialiser suspends, so without Once every caller would build.
        return $once->run(function () use (&$built) {
            $built++;
            delay(0.01);
            return "connection";
        });
    };

    $results = awaitAll(spawn($get), spawn($get), spawn($get));
    echo implode(",", $results), "\n";
    echo "built $built time(s), hasRun: ", $once->hasRun() ? "yes" : "no", "\n";
});
