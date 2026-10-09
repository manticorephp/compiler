<?php
use Manticore\Ds\Map;

final class Node { public function __construct(public string $name) {} }

$a = new Node('a'); $b = new Node('b'); $a2 = new Node('a');
$m = new Map();
$m[$a] = 1; $m[$b] = 2; $m[$a2] = 3;           // identity, not equality
echo count($m), ' ', $m[$a], ' ', $m[$a2], "\n";
foreach ($m as $k => $v) { echo $k->name, '=', $v, ' '; }
echo "\n";
try { $m->toArray(); } catch (TypeError $e) { echo $e->getMessage(), "\n"; }
unset($m[$b]);
var_dump($m->has($b), $m->has($a));
