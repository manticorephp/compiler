<?php
use Manticore\Ds\{Map, Set, Vec};
$m = new Map(); foreach (['a' => 1, 'b' => 2, 'c' => 3] as $k => $v) { $m->set($k, $v); }
$m->remove('b'); $m->set('b', 22);
foreach ($m as $k => $v) { echo "$k=$v "; } echo "\n";
$s = new Set(); foreach ([3, 1, 2] as $x) { $s->add($x); }
foreach ($s as $i => $v) { echo "$i:$v "; } echo "\n";
$v = Vec::fromArray(['x', 'y']);
foreach ($v as $i => $x) { if ($i === 0) { $v->push('z'); } echo "$i=$x "; } echo "\n";
foreach ($m as $k => $_) { if ($k === 'a') { continue; } echo $k; } echo "\n";
