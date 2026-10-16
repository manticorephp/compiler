<?php
var_export(array_unique([1, '1', 1.0, true, '01', 'a', 'A', 'a', null, false, '', 0, '0', -0.0, 1.5, '1.5']));
echo "\n";
var_export(array_unique(['x' => 'a', 'y' => 'b', 'z' => 'a', 5 => 'b', 6 => 'c']));
echo "\n";
var_export(array_unique([3, '3', 3.0, 'x3']));
echo "\n";
var_export(array_unique([]));
echo "\n";
var_export(array_unique(['b', 'a', 'b'], SORT_REGULAR));
echo "\n";
var_export(array_unique(['10', '1e1', 10], SORT_NUMERIC));
echo "\n";
$n = 20000;
$w = [];
for ($i = 0; $i < $n; $i++) { $w[] = 'w' . ($i % 7919); }
$u = array_unique($w);
echo count($u), ' ', array_key_last($u), "\n";
