<?php

use Async\CancelledException;
use Async\TaskGroup;
use function Async\async;
use function Async\delay;
use function Async\group;

async(function () {
    group(function (TaskGroup $g) {
        $token = $g->token();
        $token->onCancel(fn() => print("onCancel hook ran\n"));

        // A task that suspends is interrupted with CancelledException,
        // which extends \Error: catch (\Exception) cannot swallow it.
        $g->spawn(function () {
            try {
                delay(10.0);
            } catch (CancelledException $e) {
                echo "sleeper cancelled\n";
                throw $e;
            }
        });

        // A loop that never suspends polls the token instead.
        $g->spawn(function () use ($g, $token) {
            for ($step = 1; $step <= 5; $step++) {
                if ($step === 3) {
                    $g->cancel();   // the write half; $token is the read-only view
                }
                if ($token->isCancelled()) {
                    echo "loop stopped at step $step\n";
                    return;
                }
                echo "step $step\n";
            }
        });
    });
    echo "scope joined\n";
});
