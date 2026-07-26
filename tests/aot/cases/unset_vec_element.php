<?php

// `unset($list[1])` used to be a SILENT no-op: a packed buffer has no per-slot
// key, so it cannot carry a hole. PHP leaves a hole and never reindexes — which
// IS the hashed layout — so the unset promotes the buffer first.

$a = [1, 2, 3, 4];
unset($a[1]);
print_r($a);
var_dump(isset($a[1]), count($a), array_key_exists(2, $a));
foreach ($a as $k => $v) { echo "$k=$v "; }
echo "\n";
$a[] = 9;                       // next_int survives the promote
print_r($a);
echo json_encode($a), "\n";

$s = ['x', 'y', 'z'];
unset($s[0]);
unset($s[2]);
print_r($s);
echo implode(',', $s), "\n";

// Out of range unsets nothing (and must not pay for a promote).
$n = [1, 2, 3];
unset($n[9]);
print_r($n);

// Through a by-ref param, and through an object property.
function drop(array &$a, int $i): void { unset($a[$i]); }
$v = [10, 20, 30];
drop($v, 1);
print_r($v);

class Box { public array $items = [1, 2, 3]; }
$b = new Box();
unset($b->items[0]);
print_r($b->items);

// A variable index, an array element, and a mixed-value list.
$g = ['p', 'q', 'r'];
$k = 1;
unset($g[$k]);
print_r($g);

$nested = [[1, 2], [3, 4]];
unset($nested[0]);
print_r($nested);

$mixed = [1, 'two', 3.5, null];
unset($mixed[2]);
print_r($mixed);
print_r(array_values($mixed));

// Filtering during iteration: PHP walks a SNAPSHOT of a by-value foreach, so
// the deletions do not disturb the walk.
$loop = [1, 2, 3, 4, 5];
foreach ($loop as $i => $x) { if ($x % 2 === 0) { unset($loop[$i]); } }
print_r($loop);
echo count($loop), "\n";
echo json_encode($loop), "\n";

// An assoc unset keeps working exactly as before.
$m = ['a' => 1, 'b' => 2];
unset($m['a']);
print_r($m);
