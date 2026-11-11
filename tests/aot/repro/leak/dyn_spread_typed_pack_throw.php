<?php
// A function called by run-time name with a spread of a typed pack throws, and the packs built for the calls leak.
// issue: #647
// @serial: a memory measurement.
function boom(int ...$xs): int { throw new RuntimeException('n=' . count($xs)); }
function callI(string $f, int ...$a): mixed { return $f(...$a); }

$ints = range(1, 64);
$name = $argc > 9 ? '' : 'boom';
$caught = 0;
$m0 = memory_get_usage();
for ($i = 0; $i < 200000; $i++) {
    try {
        callI($name, ...$ints);
    } catch (RuntimeException $e) {
        $caught++;
    }
}
$d = memory_get_peak_usage() - $m0;
echo $caught, "\n";
echo $d < 20000000 ? "ok" : "LEAK " . $d, "\n";
