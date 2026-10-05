<?php
use Manticore\Ds\Int16Array;
use Manticore\Ds\Float32Array;
use Manticore\Ds\BitArray;

$a = Int16Array::fromArray([1, -2, 300]);
var_dump($a);
print_r($a->toArray());
echo json_encode($a->jsonSerialize()), ' ', json_encode(['k' => $a->toArray()]), "\n";
$s = serialize($a);
echo $s, "\n";
function back16(string $s): Int16Array { return unserialize($s); }
$r = back16($s);
echo get_class($r), ' ', count($r), ' ', $r[2], "\n";
$r[0] = 9;
echo $a[0], ' ', $r[0], "\n";

$f = Float32Array::fromArray([0.5, 0.1]);
var_dump($f->toArray());
echo json_encode($f->jsonSerialize()), "\n";
function back32(string $s): Float32Array { return unserialize($s); }
$g = back32(serialize($f));
var_dump($g[1] === $f[1]);

$b = BitArray::fromArray([true, false, 1, 0, 'x']);
var_dump($b->toArray());
echo json_encode($b->jsonSerialize()), "\n", serialize($b), "\n";
print_r(unserialize(serialize($b))->toArray());

$c = clone $a;
$c[1] = 77; $c->setSize(5);
echo json_encode($a->toArray()), ' ', json_encode($c->toArray()), "\n";
unset($a);
echo json_encode($c->toArray()), "\n";

function make(): Int16Array { $t = new Int16Array(2); $t[1] = 5; return $t; }
$sum = 0;
for ($i = 0; $i < 1000; $i++) { $t = make(); $u = clone $t; $u[0] = $i % 100; $sum += $t[1] + $u[0]; }
echo $sum, "\n";
