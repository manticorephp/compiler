<?php
// set_int on a hashed map with a live index: interleaved int-key appends,
// overwrites and string lookups. Exercises the index-keep path (Ф2a) —
// every lookup after every mutation must stay correct.
$m = [];
for ($i = 0; $i < 40; $i++) { $m["s" . $i] = $i; }
$t = 0;
for ($i = 0; $i < 40; $i++) { $t += $m["s" . $i]; }
echo "warm=", $t, "\n";

for ($i = 0; $i < 30; $i++) {
    $m[1000 + $i] = $i * 2;
    $t = $m["s" . ($i % 40)] + $m[1000 + $i];
    $m[1000 + $i] = $t;
    echo $i, ":", $m[1000 + $i], ":", count($m), "\n";
}

$m[5] = 55;
$m[1000] = -1;
$sum = 0;
foreach ($m as $k => $v) { $sum += $v; }
echo "sum=", $sum, " n=", count($m), " five=", $m[5], "\n";

$s = 0;
for ($i = 0; $i < 40; $i++) { $s += isset($m["s" . $i]) ? 1 : 0; }
for ($i = 0; $i < 30; $i++) { $s += isset($m[1000 + $i]) ? 1 : 0; }
echo "present=", $s, "\n";
