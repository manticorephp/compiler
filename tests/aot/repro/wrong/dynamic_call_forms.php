<?php
// `$s(...)`, `$c::$m()`, `$n?->f($x)` on null and string-keyed nested destructuring give wrong output
// issue: #35
final class K { public static function hi(string $w): string { return "hi $w"; } public function f(int $x): int { return $x; } }
$s = 'strtoupper';
$fc = $s(...);
echo $fc('a'), "\n";
$c = 'K';
$m = 'hi';
echo $c::$m('w'), "\n";
$n = null;
var_dump($n?->f(1));
['a' => ['b' => $x]] = ['a' => ['b' => 5]];
echo $x, "\n";
