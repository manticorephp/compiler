<?php
use Manticore\Ds\Map;
use Manticore\Ds\Set;

enum Suit { case Hearts; case Spades; }

$ints = [0, -1, 7, 1 << 47, 1 << 50, PHP_INT_MAX, PHP_INT_MIN];
$strs = ['k1', 'k' . 1, str_repeat('ab', 3), 'ababab', ''];
$o1 = new stdClass(); $o2 = new stdClass();
$objs = [$o1, $o2, Suit::Hearts];

// erased store (unbound maps, mixed keys), typed direct probe
$mixed = [...$ints, ...$strs, ...$objs];
$u = new Map();
foreach ($mixed as $i => $k) { $u->set($k, $i); }
$h = __mc_hmap_alloc(0);
foreach ($mixed as $i => $k) { __mc_hmap_put($h, $k, $i); }
foreach ($ints as $k) { echo __mc_hmap_find($h, $k), ' '; }
echo "\n";
foreach ($strs as $k) { $t = $k . ''; echo __mc_hmap_find($h, $t), ' '; }
echo "\n";
foreach ($objs as $k) { echo __mc_hmap_find($h, $k), ' '; }
echo __mc_hmap_find($h, new stdClass()), ' ', __mc_hmap_find($h, 12345), ' ', __mc_hmap_find($h, 'zz' . 1), "\n";

// erased store, bound lookup
/** @var Map<int,string> $mi */
$mi = new Map();
/** @var Map<string,int> $ms */
$ms = new Map();
/** @var Map<stdClass,int> $mo */
$mo = new Map();
/** @var Set<Suit> $se */
$se = new Set();
foreach ($ints as $i => $k) { $mi->set($k, "v$i"); }
foreach ($strs as $i => $k) { $ms->set($k, $i); }
$mo->set($o1, 1); $mo->set($o2, 2);
$se->add(Suit::Hearts);

foreach ($ints as $k) { echo $mi->get($k), ' ', $u->get($k), ' '; }
echo "\n";
foreach ($strs as $k) { $t = $k . ''; echo $ms->get($t), ' ', $u->get($t), ' ', (int)$ms->has($t), ' '; }
echo "\n";
echo $mo->get($o1), $mo->get($o2), $u->get($o2), (int)$mo->has(new stdClass()), "\n";
var_dump($se->has(Suit::Hearts), $se->has(Suit::Spades), $u->has(Suit::Hearts), $u->has(Suit::Spades));

// bound store, erased lookup
foreach ($ints as $k) { $mixedKey = $k; echo __mc_hmap_find($h, $mixedKey), ' '; }
echo "\n";
$mi->remove(PHP_INT_MIN); $ms->remove('ab' . 'abab'); $mo->remove($o1);
var_dump($mi->has(PHP_INT_MIN), $ms->has('ababab'), $mo->has($o1), count($mi), count($ms), count($mo));
