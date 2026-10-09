<?php
use Manticore\Ds\Map;

$m = new Map();
$m->set('a', 1); $m['b'] = 2; $m->set('c', 3);
echo count($m), ' ', $m->get('b'), ' ', $m['c'], "\n";
$m->set('a', 10);                       // overwrite keeps position
$m->remove('b');
$m->set('b', 20);                       // re-insert goes to the end
foreach ($m as $k => $v) { echo "$k=$v "; }
echo "\n", json_encode($m), "\n";
var_dump($m->has('zz'), isset($m['a']), $m->get('zz', 'dflt'));
unset($m['a']);
print_r($m->toArray());
try { $m->get('zz'); } catch (OutOfBoundsException $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
try { $m[] = 1; } catch (Error $e) { echo $e->getMessage(), "\n"; }
$c = clone $m; $c->set('new', 1);
echo count($m), ' ', count($c), "\n";
$m->clear();
var_dump($m->isEmpty());
echo serialize((function () { $x = new Map(); $x->set(1, 'one'); $x->set('k', 2.5); return $x; })()), "\n";
