<?php
use Manticore\Ds\Set;

$s = new Set();
foreach (['x', 'y', 'x', 1, '1', 'z'] as $v) { $s->add($v); }
echo count($s), "\n";
var_dump($s->toArray());
$t = new Set(); $t->add('y'); $t->add('w'); $t->add(1);
echo implode(',', $s->union($t)->toArray()), "\n";
echo implode(',', $s->intersect($t)->toArray()), "\n";
echo implode(',', $s->diff($t)->toArray()), "\n";
$s->remove('x');
try { $s->remove('x'); } catch (OutOfBoundsException $e) { echo $e->getMessage(), "\n"; }
foreach ($s as $i => $v) { echo "$i:$v "; }
echo "\n", json_encode($s), "\n";
try { $s->add(1.5); } catch (TypeError $e) { echo $e->getMessage(), "\n"; }
