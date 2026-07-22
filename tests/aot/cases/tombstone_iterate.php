<?php
// Tombstone correctness: after unset()s that leave holes in a HASHED map,
// every iteration primitive must see only live entries, in insertion order,
// with the right count — byte-identical to php (which also tombstones).
$m = [];
for ($i = 0; $i < 30; $i++) { $m["k" . $i] = $i * 10; }
// Unset a scattered set (holes in the middle, not just the tail).
foreach ([3, 7, 8, 15, 22, 29, 0] as $d) { unset($m["k" . $d]); }

echo "count=", count($m), "\n";

echo "foreach:";
foreach ($m as $k => $v) { echo " ", $k, "=", $v; }
echo "\n";

echo "keys=", implode(",", array_keys($m)), "\n";
echo "values=", implode(",", array_values($m)), "\n";

// count via a manual foreach must agree
$n = 0;
$sum = 0;
foreach ($m as $v) { $n++; $sum += $v; }
echo "n=", $n, " sum=", $sum, "\n";

// re-insert a couple, unset more, iterate again
$m["k3"] = 333;
$m["k7"] = 777;
unset($m["k10"]);
unset($m["k11"]);
echo "count2=", count($m), "\n";
echo "keys2=", implode(",", array_keys($m)), "\n";

// value_at / key ordering after a compaction (foreach triggers it)
$order = "";
foreach ($m as $k => $v) { $order .= $k . ":"; }
echo "order=", $order, "\n";

// array equality across an unset (both sides compacted)
$a = ["x" => 1, "y" => 2, "z" => 3];
$b = ["x" => 1, "y" => 2, "z" => 3];
unset($a["y"]);
unset($b["y"]);
var_dump($a == $b);
var_dump($a === $b);

// var_dump of a tombstoned map
$c = ["p" => 1, "q" => 2, "r" => 3];
unset($c["q"]);
var_dump($c);
