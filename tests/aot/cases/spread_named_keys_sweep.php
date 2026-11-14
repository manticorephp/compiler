<?php
// Spread arrays bind string keys by name across functions, methods, static calls and closures; func_get_args sees the spread.
function v(int $a = 0, ...$r) { return $a . json_encode($r); }
function w($a, $b = 'B', ...$r) { return "$a|$b|" . json_encode($r); }
class K { function m(int $a = 0, ...$r) { return $a . json_encode($r); } static function s($x, $y = 2) { return "$x/$y"; } }
$x = ['zz' => 1]; echo v(...$x), "\n";
$y = [5, 6, 'q' => 7]; echo v(...$y), "\n";
$z = ['r' => 1, 'a' => 9]; echo v(...$z), "\n";
echo w(...[1, 'z' => 3, 'b' => 2]), "\n";
$k = new K; echo $k->m(...['q' => 1, 'a' => 4]), "\n";
echo K::s(...['y' => 5, 'x' => 4]), "\n";
try { echo K::s(...['x' => 1, 'nope' => 2]), "\n"; } catch (\Error $e) { echo $e->getMessage(), "\n"; }
$f = fn($a, $b = 0) => "$a-$b";
try { echo $f(...['b' => 1, 'c' => 2]), "\n"; } catch (\Error $e) { echo $e->getMessage(), "\n"; }
$g = fn($a, ...$r) => $a . json_encode($r);
echo $g(...[1, 2, 'k' => 3]), "\n";
echo implode(',', (function () { return func_get_args(); })(...[1, 2, 3])), "\n";
