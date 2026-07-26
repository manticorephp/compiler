<?php

// shuffle / array_rand draw from `random_int` — this runtime has no mt_srand, so
// a draw is never reproducible. Everything asserted here is an INVARIANT: the
// multiset survives a shuffle, and every drawn key belongs to the array.

$a = [1, 2, 3, 4, 5, 6];
shuffle($a);
sort($a);
print_r($a);
echo count($a), "\n";

$strs = ['pear', 'apple', 'fig'];
shuffle($strs);
sort($strs);
print_r($strs);

// Keys are dropped by a shuffle, as in PHP.
$assoc = ['a' => 10, 'b' => 20, 'c' => 30];
shuffle($assoc);
sort($assoc);
print_r($assoc);

$empty = [];
shuffle($empty);
var_dump($empty);

$m = ['x' => 'a', 'y' => 'b', 'z' => 'c'];
$k = array_rand($m);
var_dump(is_string($k), array_key_exists($k, $m));

$two = array_rand($m, 2);
echo count($two), "\n";
$ok = true;
foreach ($two as $kk) { if (!array_key_exists($kk, $m)) { $ok = false; } }
var_dump($ok);

// Asking for every key returns them all, in the array's own order.
print_r(array_rand($m, 3));

$list = [5 => 'a', 9 => 'b'];
$lk = array_rand($list);
var_dump(is_int($lk), array_key_exists($lk, $list));

try { array_rand([], 1); } catch (\ValueError $e) { echo "empty rejected\n"; }
try { array_rand($m, 9); } catch (\ValueError $e) { echo "num rejected\n"; }
try { array_rand($m, 0); } catch (\ValueError $e) { echo "zero rejected\n"; }
