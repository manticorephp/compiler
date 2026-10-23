<?php
// A cancel() arriving while a shield()ed body is parked (delay, channel recv, a nested group)
// must not wake it; cancellation stays pending and is raised at the first suspend point after the shield.

use function Async\async;
use function Async\spawn;
use function Async\shield;
use function Async\delay;
use function Async\channel;
use function Async\group;
use Async\CancelledException;
use Async\TaskGroup;

async(function () {
    $ch = channel();
    $t = spawn(function () use ($ch) {
        try {
            $v = shield(function () use ($ch) { return $ch->recv(); });
            echo "recv got $v\n";
            delay(1.0);
            echo "not reached\n";
        } catch (CancelledException $e) {
            echo "cancelled after shield\n";
        }
    });
    delay(0.01);
    $t->cancel();
    delay(0.02);
    echo "sending\n";
    $ch->send(7);
    try { $t->await(); } catch (\Throwable $e) {}

    $t = spawn(function () {
        try {
            shield(function () {
                group(function (TaskGroup $g) {
                    $g->spawn(function () { delay(0.1); echo "child done\n"; });
                });
                echo "group joined\n";
            });
            delay(1.0);
            echo "not reached\n";
        } catch (CancelledException $e) {
            echo "cancelled after group\n";
        }
    });
    delay(0.02);
    $t->cancel();
    try { $t->await(); } catch (\Throwable $e) {}

    $t = spawn(function () {
        shield(function () { delay(0.05); });
        echo "finished without a further suspend\n";
    });
    delay(0.01);
    $t->cancel();
    try { $t->await(); } catch (\Throwable $e) {}
    echo "end\n";
});
