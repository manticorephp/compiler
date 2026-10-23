<?php

use Async\CancelledException;
use Async\TimeoutException;
use function Async\async;
use function Async\delay;
use function Async\spawn;
use function Async\timeout;

async(function () {
    // The body finishes in time: timeout() just returns its value.
    echo timeout(1.0, fn() => "in time") . "\n";

    // The deadline expires: the body AND everything it spawned are cancelled
    // and joined before TimeoutException is thrown.
    try {
        timeout(0.05, function () {
            spawn(function () {
                try {
                    delay(10.0);
                } catch (CancelledException $e) {
                    echo "child cancelled\n";
                    throw $e;
                }
            });
            delay(10.0);
        });
    } catch (TimeoutException $e) {
        echo "timed out\n";
    }
});
