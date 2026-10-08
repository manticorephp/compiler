<?php
// A callback that mutates the collection it walks (a put that compacts moves
// the epoch) throws the same RuntimeException as a foreach body, fused (a
// captureless arrow fn literal reaching the collection through a static prop)
// or not (a capturing closure in a variable).
use Manticore\Ds\{Map, Set};

final class Live
{
    public static Map $m;
    public static Set $s;
}

function poke(int $k): bool { Live::$m->set($k, 0); return true; }
function pokeSet(int $v): bool { Live::$s->add($v); return true; }

function holey(): Map
{
    $m = new Map();
    for ($i = 0; $i < 64; $i++) { $m->set($i, $i); }
    for ($i = 0; $i < 60; $i++) { $m->remove($i); }
    Live::$m = $m;
    return $m;
}

function holeySet(): Set
{
    $s = new Set();
    for ($i = 0; $i < 64; $i++) { $s->add($i); }
    for ($i = 0; $i < 60; $i++) { $s->remove($i); }
    Live::$s = $s;
    return $s;
}

$m = holey();
try { $m->each(fn($v, $k) => poke(1000 + $k)); }
catch (RuntimeException $e) { echo 'each fused: ', $e->getMessage(), "\n"; }
echo count($m), "\n";

$m = holey();
$f = function ($v, $k) use ($m) { $m->set(1000 + $k, 0); };
try { $m->each($f); }
catch (RuntimeException $e) { echo 'each var: ', $e->getMessage(), "\n"; }
echo count($m), "\n";

$m = holey();
try { $m->filter(fn($v, $k) => poke(2000 + $k)); }
catch (RuntimeException $e) { echo 'filter fused: ', $e->getMessage(), "\n"; }

$m = holey();
$g = function ($v, $k) use ($m) { $m->set(2000 + $k, 0); return true; };
try { $m->filter($g); }
catch (RuntimeException $e) { echo 'filter var: ', $e->getMessage(), "\n"; }

$m = holey();
try { echo $m->reduce(fn($c, $v, $k) => poke(3000 + $k) ? $c + $v : 0, 0), "\n"; }
catch (RuntimeException $e) { echo 'reduce fused: ', $e->getMessage(), "\n"; }

$s = holeySet();
try { $s->each(fn($v) => pokeSet(1000 + $v)); }
catch (RuntimeException $e) { echo 'set each fused: ', $e->getMessage(), "\n"; }

$s = holeySet();
$h = function ($v) use ($s) { $s->add(1000 + $v); };
try { $s->each($h); }
catch (RuntimeException $e) { echo 'set each var: ', $e->getMessage(), "\n"; }
