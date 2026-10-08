<?php
use Manticore\Ds\{Map, Set, Vec};
$m = new Map(); foreach (['a' => 1, 'b' => 2, 'c' => 3] as $k => $v) { $m->set($k, $v); }
$m->each(function ($v, $k) { echo "$k$v "; }); echo "\n";
foreach ($m->map(fn($v, $k) => $k . $v * 10) as $k => $v) { echo "$k=$v "; } echo "\n";
echo count($m->filter(fn($v) => $v % 2 === 1)), ' ', $m->reduce(fn($c, $v) => $c + $v, 0), "\n";
var_dump($m->any(fn($v) => $v > 2), $m->all(fn($v) => $v > 2), $m->find(fn($v, $k) => $k === 'b'), $m->find(fn($v) => $v > 9));
$s = new Set(); foreach ([1, 2, 3] as $x) { $s->add($x); }
echo implode(',', $s->map(fn($x) => $x % 2)->toArray()), ' ', implode(',', $s->filter(fn($x) => $x > 1)->toArray()), "\n";
$s->each(function ($x) { echo $x; }); echo ' ', $s->reduce(fn($c, $x) => $c + $x, 0), ' ';
var_dump($s->any(fn($x) => $x === 2), $s->all(fn($x) => $x < 3), $s->find(fn($x) => $x > 1), $s->find(fn($x) => $x > 5));
$vec = Vec::fromArray([5, 6, 7]);
echo implode(',', $vec->map(fn($x, $i) => $x * $i)->toArray()), ' ', $vec->reduce(fn($c, $x) => $c . $x, ''), "\n";
$k = 10; echo $vec->filter(fn($x) => $x > $k - 5)->count(), "\n";   // capturing closure
$vec->each(function ($x, $i) { echo "$i:$x "; }); echo "\n";
var_dump($vec->any(fn($x, $i) => $i === 2), $vec->all(fn($x) => $x > 5), $vec->find(fn($x, $i) => $i === 1), $vec->find(fn($x) => $x > 9));
/** @var Map<string,int> $bm */
$bm = new Map(); $bm->set('x', 4); $bm->set('y', 5);
$bm->each(function ($v, $k) { echo "$k$v "; });
echo implode(',', $bm->map(fn($v, $k) => $v * 2)->toArray()), ' ', $bm->filter(fn($v) => $v > 4)->count(), ' ', $bm->reduce(fn($c, $v) => $c + $v, 0), ' ';
var_dump($bm->any(fn($v) => $v > 4), $bm->all(fn($v) => $v > 4), $bm->find(fn($v, $k) => $k === 'y'), $bm->find(fn($v) => $v > 9));
