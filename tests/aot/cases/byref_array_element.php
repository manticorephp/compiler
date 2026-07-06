<?php
// Array-element by-reference for int-keyed local / global vecs.
function bump(int &$v) { $v += 100; }
function swap(int &$a, int &$b) { $t = $a; $a = $b; $b = $t; }

$xs = [10, 20, 30];
bump($xs[1]);
echo $xs[0], " ", $xs[1], " ", $xs[2], "\n";   // 10 120 30

swap($xs[0], $xs[2]);
echo $xs[0], " ", $xs[2], "\n";                 // 30 10

// ref-assign to a local element, mutate through the alias
$e = &$xs[1];
$e = 5;
$e++;
echo $xs[1], "\n";                              // 6

// value semantics preserved: a copy taken before the ref-write is unaffected
$a = [1, 2, 3];
$b = $a;
bump($a[0]);
echo $a[0], " ", $b[0], "\n";                   // 101 1

// auto-vivification of an absent index (PHP by-ref creates it)
$g = [1, 2];
$r = &$g[4];
$r = 9;
echo $g[4], " ", count($g), "\n";               // 9 3

// build a list of refs in a loop
$zs = [0, 0, 0, 0];
for ($i = 0; $i < 4; $i++) { $p = &$zs[$i]; $p = $i * $i; }
echo $zs[0], $zs[1], $zs[2], $zs[3], "\n";      // 0149

// global vec element by-ref
$G = [7, 8, 9];
function tweak() { global $G; bump2($G[1]); }
function bump2(int &$v) { $v = 88; }
tweak();
echo $G[1], "\n";                               // 88
