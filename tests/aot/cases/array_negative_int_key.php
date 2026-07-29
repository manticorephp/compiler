<?php

// A negative int key is a legal PHP key. On a PACKED buffer the set path used a
// bare signed `idx < len` in-bounds test, so `$a[-3] = x` stored BEFORE the
// buffer, over the header — count() then read garbage and foreach saw nothing.

$a = [-3 => 'neg'];
echo count($a), "\n";
foreach ($a as $k => $v) { echo $k, '=', $v, "\n"; }

$b = [];
$b[-3] = 'neg';
echo count($b), "\n";

$c = [];
$c[0] = 'z';
$c[-3] = 'neg';
echo count($c), "\n";
foreach ($c as $k => $v) { echo $k, '=', $v, "\n"; }
echo $c[-3], "\n";
echo isset($c[-3]) ? "set\n" : "unset\n";
echo isset($c[-9]) ? "set\n" : "unset\n";
unset($c[-3]);
echo count($c), "\n";

// A negative key mixed with appends keeps the next-int cursor at 0.
$d = [-1 => 'a'];
$d[] = 'b';
foreach ($d as $k => $v) { echo $k, '=', $v, "\n"; }

// Still packed and dense after a legal in-bounds overwrite.
$e = [10, 20, 30];
$e[1] = 99;
echo count($e), ' ', $e[0], ' ', $e[1], ' ', $e[2], "\n";
