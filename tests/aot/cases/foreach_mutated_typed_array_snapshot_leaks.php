<?php
// The foreach snapshot of a typed-element array mutated in its own loop is released at loop exit.
class P { public function __construct(public int $n) {} }
function ints(): int {
    $a = [];
    for ($i = 0; $i < 20000; $i++) { $a[] = $i; }
    $n = 0;
    foreach ($a as $k => $v) { $a[$k] = $v + 1; $n += $v; }
    return $n + $a[5];
}
function strs(): int {
    $a = [];
    for ($i = 0; $i < 2000; $i++) { $a[] = "s" . $i; }
    $n = 0;
    foreach ($a as $k => $v) { $a[$k] = $v . "x"; $n += strlen($v); }
    return $n + strlen($a[7]);
}
function objs(): int {
    $a = [];
    for ($i = 0; $i < 2000; $i++) { $a[] = new P($i); }
    $n = 0;
    foreach ($a as $k => $v) { $a[$k] = new P($v->n + 1); $n += $v->n; }
    return $n + $a[3]->n;
}
function nested(): int {
    $a = [];
    for ($i = 0; $i < 2000; $i++) { $a[] = [$i, $i + 1]; }
    $n = 0;
    foreach ($a as $k => $v) { $a[$k] = [$v[0] + 1]; $n += $v[1]; }
    return $n + $a[3][0];
}
$m0 = memory_get_peak_usage();
$s = 0;
for ($j = 0; $j < 300; $j++) { $s += ints() + strs() + objs() + nested(); }
$mb = (memory_get_peak_usage() - $m0) >> 20;
echo $s, "\n";
echo $mb < 8 ? "flat\n" : "LEAK\n";
