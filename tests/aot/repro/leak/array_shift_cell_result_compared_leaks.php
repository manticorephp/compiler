<?php
// array_shift of a mixed list whose result is only compared with === null leaks the shifted element
// issue: #314
function f(int $n): int
{
    $q = [];
    $t = 0;
    for ($i = 0; $i < $n; $i++) {
        $q[] = str_repeat('x', 64) . $i;
        $q[] = [$i];
        if (array_shift($q) === null) { $t++; }
        array_shift($q);
    }
    return $t;
}
f(1000);
$m0 = memory_get_peak_usage();
f(300000);
echo (memory_get_peak_usage() - $m0) < 4000000 ? "flat" : "LEAK", "\n";
