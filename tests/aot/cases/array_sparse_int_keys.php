<?php
// Sparse int keys must survive (dense-vec promotes to hashed) through stores,
// var_dump, and a key-preserving cell boundary.
$o = [];
$o[1] = 2;
$o[3] = 4;
$o[10] = 5;
echo $o[1], ",", $o[3], ",", $o[10], ",", count($o), "\n";
var_dump($o);
function show($a) { $p = []; foreach ($a as $k => $v) { $p[] = $k . "=" . $v; } echo implode(",", $p), "\n"; }
show($o);                                          // cell boundary (untyped param)
show(array_filter([1, 2, 3, 4, 5, 6], fn($x) => $x % 2 === 0)); // filter keeps source indices
