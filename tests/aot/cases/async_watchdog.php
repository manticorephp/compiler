<?php

// The loop-hog watchdog: a task that never suspends stalls EVERY other task, and
// nothing used to say so. The human-facing report goes to STDERR (naming the task
// and its spawn site); the counter in Async\stats() is what a test can pin.

use function Async\async;
use function Async\spawn;

/** Burn CPU for $seconds without ever reaching a suspend point. */
function hog(float $seconds): float
{
    $x = 0.0;
    $end = microtime(true) + $seconds;
    while (microtime(true) < $end) { $x = $x + 1.0; }
    return $x;
}

$out = async(function (): string {
    Async\watchdog(30.0);
    $ticker = spawn(function (): int {
        for ($i = 0; $i < 5; $i = $i + 1) { Async\delay(0.01); }
        return 5;
    })->named('ticker');
    $hog = spawn(function (): float { return hog(0.15); })->named('hog');
    $ticks = $ticker->await();
    $hog->await();
    $s = Async\stats();
    return 'ticks=' . (string)$ticks
        . ' watchdog=' . (string)($s['watchdog'] >= 1 ? 1 : 0)
        . ' spawned=' . (string)$s['spawned'];
});
echo $out, "\n";

// Off by default: the same hog with no threshold set reports nothing.
$quiet = async(function (): int {
    spawn(function (): float { return hog(0.05); })->await();
    return Async\stats()['watchdog'];
});
echo 'quiet=', $quiet, "\n";

// Outside a scheduler there is nothing to count.
echo 'outside=', count(Async\stats()), "\n";
