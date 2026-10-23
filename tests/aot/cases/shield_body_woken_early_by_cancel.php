<?php
// A cancel() that arrives while a shield()ed body is parked in delay() wakes it early instead of letting it finish
use function Async\async;
use function Async\spawn;
use function Async\shield;
use function Async\delay;

async(function () {
    $t0 = hrtime(true);
    $t = spawn(function () use ($t0) {
        try {
            shield(function () use ($t0) {
                delay(0.2);
                echo 'shield body finished after ', (hrtime(true) - $t0) >= 190_000_000 ? 'the full delay' : 'an early wake-up', "\n";
            });
        } catch (\Async\CancelledException $e) {
        }
    });
    delay(0.02);
    $t->cancel();
    try { $t->await(); } catch (\Throwable $e) {}
});
