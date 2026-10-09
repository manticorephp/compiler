<?php
use Manticore\Ds\Vec;
use Manticore\Ds\Map;

$v = Vec::fromArray(['a', 'b']);
$v->push('c'); $v[] = 'd';
echo count($v), ' ', $v[0], $v[3], "\n";
$v[1] = 'B';
echo $v->pop(), ' ', implode(',', $v->toArray()), "\n";
try { $v[10]; } catch (OutOfBoundsException $e) { echo $e->getMessage(), "\n"; }
try { $v['x'] = 1; } catch (TypeError $e) { echo $e->getMessage(), "\n"; }
$e = new Vec();
try { $e->pop(); } catch (UnderflowException $x) { echo $x->getMessage(), "\n"; }
foreach ($v as $i => $x) { echo "$i:$x "; }
echo "\n", json_encode($v), "\n";
$w = clone $v; $w[] = 'z'; echo count($v), count($w), "\n";
$m = new Map(); $m->set('p', 1.5); $m->set('q', null);
var_dump($m->keys()->toArray(), $m->values()->toArray());
var_dump($v);
