<?php

use Async\CancelledException;
use function Async\async;
use function Async\delay;
use function Async\shield;
use function Async\spawn;

async(function () {
    $worker = spawn(function () {
        try {
            delay(10.0);
        } catch (CancelledException $e) {
            // Cancellation is re-raised at every suspend point, so cleanup
            // that itself suspends must be shielded.
            shield(function () {
                delay(0.01);
                echo "cleanup finished\n";
            });
            throw $e;
        }
    });

    delay(0.01);
    $worker->cancel();

    // join() reports how the task ended without rethrowing it here.
    $outcome = $worker->join();
    echo $outcome->ok ? "completed\n" : "ended with " . get_class($outcome->error) . "\n";
});
