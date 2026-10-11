<?php
// An array union (`+` / `+=`) stored into an array element never releases the fresh result buffer.
// issue: #193
function churn(int $n): int
{
    $keep = [['a' => 1, 'b' => 2]];
    $p = ['c' => 3, 'd' => 4];
    $len = 0;
    for ($i = 0; $i < $n; $i++) { $k = $keep; $k[0] += $p; $len += count($k[0]); }
    return $len;
}
churn(2000);
$before = memory_get_peak_usage();
churn(200000);
echo memory_get_peak_usage() - $before < 2 << 20 ? "flat\n" : "grows\n";
