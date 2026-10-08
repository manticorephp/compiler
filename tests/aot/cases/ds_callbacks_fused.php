<?php
use Manticore\Ds\{Map, Set, Vec};

function show(mixed $x): string { return var_export($x, true); }

$m = new Map(); foreach (['a' => 1, 'b' => 2, 'c' => 3] as $k => $v) { $m->set($k, $v); }
$m->each(fn($v, $k) => printf('%s%d ', $k, $v)); echo "\n";
foreach ($m->map(fn($v, $k) => $k . $v * 10) as $k => $v) { echo "$k=$v "; } echo "\n";
echo count($m->filter(fn($v) => $v % 2 === 1)), ' ', $m->reduce(fn($c, $v) => $c + $v, 0), ' ', $m->reduce(fn($c, $v, $k) => $c . $k, '>'), "\n";
echo show($m->any(fn($v) => $v > 2)), show($m->all(fn($v) => $v > 2)), show($m->find(fn($v, $k) => $k === 'b')), show($m->find(fn($v) => $v > 9)), "\n";

$s = new Set(); foreach ([1, 2, 3, 4] as $x) { $s->add($x); }
echo implode(',', $s->map(fn($x) => $x % 2)->toArray()), ' ', implode(',', $s->filter(fn($x) => $x > 1)->toArray()), "\n";
$s->each(fn($x) => printf('%d', $x)); echo ' ', $s->reduce(fn($c, $x) => $c + $x, 0), ' ';
echo show($s->any(fn($x) => $x === 2)), show($s->all(fn($x) => $x < 5)), show($s->find(fn($x) => $x > 1)), show($s->find(fn($x) => $x > 5)), "\n";

$vec = Vec::fromArray([5, 6, 7]);
echo implode(',', $vec->map(fn($x, $i) => $x * $i)->toArray()), ' ', $vec->reduce(fn($c, $x) => $c . $x, ''), ' ', $vec->filter(fn($x) => $x > 5)->count(), "\n";
$vec->each(fn($x, $i) => printf('%d:%d ', $i, $x)); echo "\n";
echo show($vec->any(fn($x, $i) => $i === 2)), show($vec->all(fn($x) => $x > 5)), show($vec->find(fn($x, $i) => $i === 1)), show($vec->find(fn($x) => $x > 9)), "\n";

/** @var Map<string,int> $bm */
$bm = new Map(); $bm->set('x', 4); $bm->set('y', 5);
echo implode(',', $bm->map(fn($v, $k) => $v * 2)->toArray()), ' ', $bm->filter(fn($v) => $v > 4)->count(), ' ', $bm->reduce(fn($c, $v) => $c + $v, 0), ' ';
echo show($bm->any(fn($v) => $v > 4)), show($bm->all(fn($v) => $v > 4)), show($bm->find(fn($v, $k) => $k === 'y')), "\n";

// Not spliced: a closure value, a typed param (coerces). (A typed return is refused too; its coercion is #142.)
$f = fn($v) => $v + 100;
echo implode(',', $m->map($f)->toArray()), ' ';
echo implode(',', $m->map(fn(string $v) => $v . '!')->toArray()), "\n";

// A throwing callback propagates out of the loop.
try { $m->map(fn($v, $k) => $v === 2 ? throw new RuntimeException("at $k") : $v); }
catch (RuntimeException $e) { echo $e->getMessage(), "\n"; }

// A literal callback that throws: the exception propagates, the loop's key and
// value copies are released (map/reduce throwing shapes leak on #143/#144, both
// spliced and not, and are left out).
function round_(int $i): int
{
    $m = new Map();
    for ($j = 0; $j < 4; $j++) { $m->set("k$j", str_repeat('v', 200) . $i . '.' . $j); }
    $n = 0;
    try { $m->filter(fn($v, $k) => $k === 'k2' ? throw new RuntimeException($v) : true); }
    catch (RuntimeException $e) { $n += strlen($e->getMessage()); }
    try { $m->any(fn($v, $k) => $k === 'k1' ? throw new RuntimeException($k) : false); }
    catch (RuntimeException $e) { $n += strlen($e->getMessage()); }
    $s = new Set();
    for ($j = 0; $j < 3; $j++) { $s->add(str_repeat('s', 100) . $i . '.' . $j); }
    try { $s->each(fn($x) => strlen($x) > 0 ? throw new RuntimeException($x) : 0); }
    catch (RuntimeException $e) { $n += strlen($e->getMessage()); }
    $w = Vec::fromArray([str_repeat('w', 100) . $i, 'b', 'c']);
    try { $w->all(fn($x, $j) => $j === 1 ? throw new RuntimeException($x) : true); }
    catch (RuntimeException $e) { $n += strlen($e->getMessage()); }
    return $n + strlen((string)$m->find(fn($v, $k) => $k === 'k3'));
}

$t = 0;
for ($i = 0; $i < 200; $i++) { $t += round_($i); }
$before = memory_get_peak_usage();
for ($i = 0; $i < 20000; $i++) { $t += round_($i); }
echo $t, "\n";
echo memory_get_peak_usage() - $before < 2 << 20 ? "flat\n" : "grows\n";
