<?php
// A spread covering both a fixed parameter and the variadic one of a known closure segfaults.
$g = fn($a, ...$r) => $a . ':' . implode(',', $r);
$arr = ['x', 'y', 'z'];
echo $g(...$arr), "\n";
echo $g(...['only']), "\n";
$h = function (int $a, int $b, int ...$r) { return $a + $b + array_sum($r); };
echo $h(...[1, 2, 3, 4]), "\n";
echo $h(1, ...[2, 3, 4]), "\n";
function f($a, ...$r) { return $a . ':' . implode(',', $r); }
echo f(...$arr), "\n";
class C { function m($a, ...$r) { return $a . ':' . implode(',', $r); } static function s($a, ...$r) { return $a . ':' . implode(',', $r); } }
echo (new C)->m(...$arr), "\n";
echo C::s(...$arr), "\n";
try { echo $h(...[1]), "\n"; } catch (ArgumentCountError $e) { echo get_class($e), "\n"; }
