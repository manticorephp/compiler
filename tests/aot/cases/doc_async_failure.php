<?php

use Async\CancelledException;
use Async\TaskGroup;
use function Async\async;
use function Async\delay;

try {
    async(function () {
        TaskGroup::run(function (TaskGroup $g) {
            $g->spawn(function () {
                try {
                    delay(10.0);
                } catch (CancelledException $e) {
                    echo "sibling cancelled\n";
                    throw $e;
                }
            });
            $g->spawn(function () {
                delay(0.01);
                throw new RuntimeException("child failed");
            });
        });
        echo "not reached\n";
    });
} catch (RuntimeException $e) {
    // The first failure cancelled the sibling, the scope joined it, and the
    // original exception came out of the scope.
    echo "caught: ", $e->getMessage(), "\n";
}
