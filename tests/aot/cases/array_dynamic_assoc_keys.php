<?php
// An array whose static type is erased (a bare-array function return) but holds
// string keys at runtime must iterate/rebuild with its real keys + values, not
// raw pointers — both on a direct foreach AND across a cell boundary (an
// untyped `show()` param, which boxes the array to a cell-array).
function mk() { return ["x" => 1, "y" => 2, "z" => 3]; }
function copymap($a) { $o = []; foreach ($a as $k => $v) { $o[$k] = $v * 10; } return $o; }
function show($a) { $p = []; foreach ($a as $k => $v) { $p[] = $k . "=" . $v; } echo implode(",", $p), "\n"; }

$r = copymap(mk());                                // hand map over erased assoc
foreach ($r as $k => $v) { echo $k, "=", $v, " "; } echo "\n";

foreach (array_map(fn($v) => $v * 100, mk()) as $k => $v) { echo $k, "=", $v, " "; } echo "\n";
foreach (array_filter(mk(), fn($v) => $v > 1) as $k => $v) { echo $k, "=", $v, " "; } echo "\n";

show(array_map(fn($v) => $v * 100, mk()));         // erased assoc map across a cell boundary
show(array_filter(mk(), fn($v) => $v > 1));        // erased assoc filter across a cell boundary
