<?php

use function Async\async;
use function Async\delay;
use function Async\mapConcurrent;

$inFlight = 0;
$peak = 0;

$squares = async(function () use (&$inFlight, &$peak) {
    // Square five numbers, never more than two at a time.
    return mapConcurrent([1, 2, 3, 4, 5], function (int $n) use (&$inFlight, &$peak) {
        $inFlight++;
        $peak = max($peak, $inFlight);
        delay(0.01);
        $inFlight--;
        return $n * $n;
    }, 2);
});

echo implode(",", $squares), "\n";
echo "peak in flight: $peak\n";
