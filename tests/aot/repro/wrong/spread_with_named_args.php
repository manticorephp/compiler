<?php
// Named arguments after a spread, and a string-keyed spread, are dropped or mis-bound instead of mapped to parameter names.
// issue: #462
function f(int $a, int $b = 5, bool $boolean = false) { return "$a-$b-" . ($boolean ? 'T' : 'F'); }
class Q { public function w($col, $op = null, $v = null, $boolean = 'and') { return "$col|$op|$v|$boolean"; } }
echo f(...[1, 2], boolean: true), "\n";
echo f(...['a' => 1, 'boolean' => true]), "\n";
$q = new Q;
$m = 'w';
echo $q->{$m}(...['a', '=', 3], boolean: 'or'), "\n";
echo $q->{$m}('a', boolean: 'or'), "\n";
$c = fn(int $a, int $b = 2, $z = 'zz') => "$a $b $z";
echo $c(...[1], z: 'Q'), "\n";
