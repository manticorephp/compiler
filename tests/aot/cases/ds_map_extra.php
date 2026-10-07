<?php
use Manticore\Ds\Map;
use Manticore\Ds\Set;

final class K { public function __construct(public string $n) {} }

$m = new Map();
$m[1] = 'a'; $m->set('s', [1, 2]); $m->set(new K('obj'), 'o');
$r = unserialize(serialize($m));
echo count($r), "\n";
foreach ($r as $k => $v) { echo is_object($k) ? 'K:' . $k->n : var_export($k, true), '=', json_encode($v), ' '; }
echo "\n";

$s = new Set(); $s->add('a'); $s->add(2);
$sr = unserialize(serialize($s));
echo count($sr), ' ', implode(',', $sr->toArray()), "\n";
$c = clone $s; $c->add('z'); $s->remove('a');
echo implode(',', $s->toArray()), ' | ', implode(',', $c->toArray()), "\n";

$x = new Map(); $x->set('a', 1); $x->set('b', 2);
try { foreach ($x as $k => $v) { $x->clear(); } } catch (RuntimeException $e) { echo $e->getMessage(), "\n"; }
echo count($x), "\n";

$col = new Map(); $col->set(1, 'i'); $col->set('1', 's');
echo count($col), "\n";
try { $col->toArray(); } catch (ValueError $e) { echo $e->getMessage(), "\n"; }

$sj = new Map(); $sj->set("a", 1);
echo json_encode($sj), "\n";
echo json_encode(new Set()), "\n";
echo json_encode(new Manticore\Ds\Map()), "\n";
$ji = new Map(); $ji->set(0, 'a'); $ji->set(5, 'b');
echo json_encode($ji), "\n";
