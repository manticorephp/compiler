<?php
// Adding a new key (or clear) while foreach walks a Map / Set throws; overwriting a value or removing keys does not.
use Manticore\Ds\Map;
use Manticore\Ds\Set;

$m = new Map();
$m->set('a', 1);
$m->set('b', 2);
foreach ($m as $k => $v) { $m->set($k, $v * 10); }
echo json_encode($m->toArray()), "\n";

$try = function (string $what, callable $f) {
    try { $f(); echo "$what: finished\n"; }
    catch (\RuntimeException $e) { echo "$what: ", $e->getMessage(), "\n"; }
};
$try('map insert', function () use ($m) { foreach ($m as $k => $v) { $m->set('n' . $k, 1); } });
$m = new Map(); $m->set('a', 1); $m->set('b', 2);
$try('map remove', function () use ($m) { foreach ($m as $k => $v) { $m->remove($k); } });
$m = new Map(); $m->set('a', 1);
$try('map clear', function () use ($m) { foreach ($m as $k => $v) { $m->clear(); } });
$s = new Set(); $s->add(1);
$try('set add', function () use ($s) { foreach ($s as $v) { $s->add($v + 100); } });
$s = new Set(); $s->add(1); $s->add(2);
$try('set re-add', function () use ($s) { foreach ($s as $v) { $s->add($v); } });
$s = new Set(); $s->add(1); $s->add(2);
$try('set remove', function () use ($s) { foreach ($s as $v) { $s->remove($v); } });
