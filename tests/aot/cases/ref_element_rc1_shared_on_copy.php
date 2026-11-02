<?php
// An array element that was once referenced keeps sharing its reference box after the reference is gone, so a copy of the array aliases the original.
$a = [1, 2];
$r = [&$a[0]];
unset($r);
$b = $a;
$b[0] = 9;
echo $a[0], "\n";
function inc(&$x) { $x++; }
function f(array &$args) { inc(...$args); }
$c = [10];
f($c);
$d = $c;
$d[0] = 99;
echo $c[0], "\n";
$e = [1, 2];
foreach ($e as &$v) { $v *= 10; }
unset($v);
$g = $e;
$g[0] = 5;
echo $e[0], " ", $g[0], "\n";
$h = [1];
$s = &$h[0];
unset($s);
$i = $h;
$i[0] = 3;
echo $h[0], "\n";
$j = [5];
inc(...$j);
$k = $j;
$k[0] = 0;
echo $j[0], "\n";
$m = ['x' => 1];
$n = [&$m['x']];
unset($n);
$o = $m;
$o['x'] = 2;
echo $m['x'], "\n";
