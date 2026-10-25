<?php
function f(int $a, int $b = 5, bool $boolean = false) { return "$a-$b-" . ($boolean ? 'T' : 'F'); }
class Q { public function w($col, $op = null, $v = null, $boolean = 'and') { return "$col|$op|$v|$boolean"; } }
echo f(...[1, 2], boolean: true), "\n";
echo f(...['a' => 1, 'boolean' => true]), "\n";
$p = [3];
echo f(...$p, boolean: true), "\n";
echo f(...$p, b: 9), "\n";
$q = new Q;
$m = 'w';
echo $q->w(...['a', '=', 3], boolean: 'or'), "\n";
echo $q->{$m}(...['a', '=', 3], boolean: 'or'), "\n";
echo $q->{$m}('a', boolean: 'or'), "\n";
echo $q->{$m}(...['a'], op: '>', boolean: 'or'), "\n";
