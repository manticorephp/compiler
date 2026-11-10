<?php
// A user function called by a run-time name with more arguments than it declares throws "Call to undefined function" instead of ignoring the extra ones.
function addOne(int &$v, int $k): void { $v += 1; }
$v = 1;
$f = $argc > 9 ? '' : 'addOne';
$f($v, 2, 3);
echo $v, "\n";
$a = [1, 2];
array_walk($a, $f);
echo implode(',', $a), "\n";
