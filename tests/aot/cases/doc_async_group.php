<?php

use Async\TaskGroup;
use function Async\async;
use function Async\delay;
use function Async\group;

async(function () {
    // group() opens a child scope. It returns the body's value, but only
    // after every task spawned inside has settled.
    $answer = group(function (TaskGroup $g) {
        $g->spawn(function () {
            delay(0.02);
            echo "second child done\n";
        });
        $g->spawn(function () {
            delay(0.01);
            echo "first child done\n";
        });
        echo "body finished\n";
        return 42;
    });

    echo "group returned $answer\n";
});
