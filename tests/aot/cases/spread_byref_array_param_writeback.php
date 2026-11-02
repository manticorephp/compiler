<?php
class A { public function inc(&$x) { $x = $x + 1; return $x; } }
function inc(&$x) { $x = $x + 1; return $x; }
function direct(A $o, array &$args) { return $o->inc(...$args); }
function viaFn(array &$args) { return inc(...$args); }
function elemRef(array &$a) { $r = &$a[0]; $r++; }
function elemRefLoop(array &$a) { foreach ($a as $k => $_) { $r = &$a[$k]; $r++; } }
$a = [10]; echo direct(new A, $a), " ", $a[0], "\n";
$b = [10]; echo viaFn($b), " ", $b[0], "\n";
$c = [10]; elemRef($c); echo $c[0], "\n";
$d = [10, 20]; elemRefLoop($d); echo $d[0], " ", $d[1], "\n";
