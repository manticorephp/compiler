<?php
// array_shift / array_pop of an emptied int list answer int(0) instead of NULL
$a = [1];
array_shift($a);
var_dump(array_shift($a));
$b = [2];
array_pop($b);
var_dump(array_pop($b));
$a = [1]; array_shift($a); var_dump(array_shift($a));
$b = [2]; array_pop($b); var_dump(array_pop($b));
$f = [1.5]; array_pop($f); var_dump(array_pop($f));
$t = [true]; array_pop($t); var_dump(array_shift($t));
$s = ['x']; array_pop($s); var_dump(array_pop($s), array_shift($s));
$o = [new stdClass]; array_pop($o); var_dump(array_pop($o));
$arr = [[1]]; array_pop($arr); var_dump(array_pop($arr));
$n = [3, 4, 5]; var_dump(array_pop($n), array_shift($n), array_pop($n) + 1, array_pop($n));
$fl = [1.5, 2.5]; var_dump(array_pop($fl), array_shift($fl) * 2);
$bl = [true, false]; var_dump(array_pop($bl), array_shift($bl));
$st = [5, 6]; $sum = 0;
while (($x = array_pop($st)) !== null) { $sum += $x; } var_dump($sum);
$q = [7, 8]; while (null !== $y = array_shift($q)) { echo $y, "\n"; }
function g(array $l): int { $z = array_pop($l); return $z * 2; } var_dump(g([4]));
$e = []; var_dump(array_pop($e), array_shift($e));
$k = [1,2,3]; $i = array_pop($k); echo $k[$i - 3], " ", intdiv(array_pop($k), 1), "\n";
