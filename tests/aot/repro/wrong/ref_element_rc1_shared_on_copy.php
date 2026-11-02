<?php
// An array element that was once referenced keeps sharing its reference box after the reference is gone, so a copy of the array aliases the original.
// issue: #548
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
