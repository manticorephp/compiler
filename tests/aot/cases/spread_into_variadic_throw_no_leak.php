<?php
// A spread into a variadic function that throws leaks the pack built for the call.
function boom(int ...$xs): int { throw new RuntimeException('n=' . count($xs)); }
$ints = range(1, 64);
$m0 = memory_get_usage();
for ($i = 0; $i < 20000; $i++) {
    try { boom(...$ints); } catch (RuntimeException $e) {}
}
$d = memory_get_peak_usage() - $m0;
echo $d < 5000000 ? "flat" : "LEAK " . $d, "\n";
