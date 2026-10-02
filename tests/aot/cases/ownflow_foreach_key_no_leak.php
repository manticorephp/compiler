<?php
// A foreach KEY var is always blocked today; string keys minted per iteration leak.
// @serial: a memory measurement.
function keys(int $i): int {
    $m = [];
    for ($j = 0; $j < 8; $j++) { $m['k' . $i . '_' . $j] = $j; }
    $n = 0;
    foreach ($m as $k => $v) { $n += strlen($k) + $v; $k = 'x' . $v; $n += strlen($k); }
    return $n;
}
$sum = 0;
for ($i = 0; $i < 2000; $i++) { $sum += keys($i); }
$b = memory_get_usage();
for ($i = 0; $i < 100000; $i++) { $sum += keys($i); }
$g = memory_get_usage() - $b;
echo 'sum=', $sum, ' ', $g < 3 * 1024 * 1024 ? 'growth ok' : 'growth=' . round($g / 1048576, 1) . 'MB', "\n";
