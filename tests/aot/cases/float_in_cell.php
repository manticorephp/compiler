<?php
// Canonical NaN-boxing: a float stored in a cell (array element / object prop)
// round-trips losslessly, AND var_dump of a cell float prints the SHORTEST
// decimal (serialize_precision -1), matching a typed float — not the precision-14
// (string) cast. Values needing >14 sig figs exercise the shortest path.
$a = [0.1, 0.2, 0.30000000000000004, 1, "s"];
var_dump($a[0]);
var_dump($a[2]);
var_dump($a);

class Box { public mixed $v; }
$b = new Box();
$b->v = 0.30000000000000004;
var_dump($b->v);
var_dump($b);

// closure cell+cell float fold (array_reduce) + shortest var_dump.
$r = array_reduce([0.1, 0.2, 0.3], fn($c, $x) => $c + $x, 0.0);
var_dump($r);
echo array_sum([0.1, 0.2, 0.3]), "\n";
