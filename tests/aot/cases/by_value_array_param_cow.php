<?php
// PHP arrays are values: a by-VALUE array param mutated in place must not touch
// the caller's array. Copy-on-entry for a param whose element type is a known
// SCALAR/string (the flat __mir_array_copy fully separates it). A by-ref param
// still writes through; a read-only param needs no copy.
function push(array $x): int { $x[] = 99; return count($x); }
$a = [1, 2];
$n = push($a);
echo count($a), " ", $n, "\n";           // 2 3

function pushStr(array $x): int { $x[] = "z"; return count($x); }
$s = ["a", "b"];
$m = pushStr($s);
echo count($s), " ", $m, "\n";            // 2 3

function twice(array $x): int { $x[] = 8; $x[] = 9; return count($x); }
$t = [1];
$k = twice($t);
echo count($t), " ", $k, "\n";            // 1 3

function byref(array &$x): void { $x[] = 7; }
$b = [1, 2];
byref($b);
echo count($b), "\n";                     // 3

function ro(array $x): int { return count($x); }
$c = [1, 2, 3];
echo ro($c), " ", count($c), "\n";        // 3 3

// Nested by-value array param: `$x[0][] = …` must not reach the caller's inner
// buffer either — the deep copy clones every nested level.
function nest1(array $x): void { $x[0][] = 99; }
$g = [[1], [2]];
nest1($g);
echo count($g[0]), " ", count($g[1]), "\n";   // 1 1

function nest2(array $x): void { $x[0][0][] = 5; }
$h = [[[1]], [[2]]];
nest2($h);
echo count($h[0][0]), "\n";                     // 1
