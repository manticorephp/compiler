<?php
use Manticore\Ds\Map;
/** @var Map<int,string> $m */
$m = new Map();
foreach ([0, -1, 7, 1 << 47, 1 << 50, PHP_INT_MAX, PHP_INT_MIN] as $k) { $m->set($k, "v$k"); }
$m->set("7", 'string-seven');
$keys = [0, -1, 7, 1 << 47, 1 << 50, PHP_INT_MAX, PHP_INT_MIN];
foreach ($keys as $k) { echo $k, '=', $m->get($k), "\n"; }
echo $m->get("7"), ' ', count($m), "\n";
$k = 1 << 50; $m->remove($k); var_dump($m->has(1 << 50), $m->has($k + 0));
$o = new stdClass(); $s = 'k' . 1;
$x = new Map(); $x->set($o, 1); $x->set($s, 2); $x->set('k1', 3);
var_dump($x->get($o), $x->get('k1'), count($x));
