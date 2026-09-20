<?php
function add1(mixed $c): mixed { return $c + 1; }
function mul(mixed $a, mixed $b): mixed { return $a * $b; }
function cat(mixed $a): string { return $a . "!"; }
var_dump(add1("1"), add1(1), add1(1.5), add1("2.5"), add1(true), add1(null));
var_dump(mul("3", "4"), mul(70000, 70000), mul("1e3", 2));
var_dump(cat(70000), cat(1.5), cat("s"), cat(true), cat(null));
$m = ["1", 2, 3.5];
$s = 0;
foreach ($m as $v) { $s += $v; }
var_dump($s);
var_dump($m[0] + $m[1], $m[0] . $m[1], $m[1] | 8, $m[0] == 1, $m[2] > $m[1]);
