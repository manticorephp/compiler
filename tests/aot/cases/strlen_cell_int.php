<?php
// strlen() of an int held in a cell (a generator's auto key) reads the int as a string pointer and crashes
function g(): \Generator { yield 'a' => 1; yield 2; yield 3; }
foreach (g() as $k => $v) { echo strlen($k), "\n"; }
function mixedv(int $i): mixed { return $i % 2 === 0 ? $i * 1000 : ($i % 3 === 0 ? true : "s$i"); }
for ($i = 0; $i < 6; $i++) {
    $v = mixedv($i);
    echo strlen($v), ' ', substr($v, 0, 2), ' ', strtoupper($v), ' ', str_pad($v, 6, '.'), "\n";
}
function fv(int $i): mixed { return [1.5, 1.1, -0.0, 1e100, 0.1 + 0.2, INF, NAN][$i]; }
for ($i = 0; $i < 7; $i++) {
    $v = fv($i);
    echo strlen($v), ' ', strtoupper($v), ' ', ucfirst($v), ' ', str_pad($v, 8, '.'), "\n";
}
$m0 = memory_get_peak_usage();
$t = 0;
for ($i = 0; $i < 1000000; $i++) { $t += strlen(mixedv($i * 2 + 2)); }
echo $t, "\n";
echo ((memory_get_peak_usage() - $m0) >> 20) < 8 ? "flat\n" : "LEAK\n";
