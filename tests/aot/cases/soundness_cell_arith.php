<?php
// (3) cell - cell -> unknown type, raw NaN-boxed bit subtraction
function g(): mixed { return 2.5; }
function h(): mixed { return 1.5; }
$a = g(); $b = h();
var_dump($a);
var_dump($b - $a);
var_dump(gettype($b - $a));
echo "done\n";
