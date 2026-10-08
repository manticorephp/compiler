<?php
// A fresh Map/Set/Vec straight from a call as a foreach subject or as the
// receiver of a fused callback method is released once the walk ends.
use Manticore\Ds\{Map, Set, Vec};

function mkMap(int $i): Map { $m = new Map(); for ($j = 0; $j < 4; $j++) { $m->set("k$j", str_repeat('m', 200) . $i); } return $m; }
function mkSet(int $i): Set { $s = new Set(); for ($j = 0; $j < 4; $j++) { $s->add(str_repeat('s', 200) . $i . '.' . $j); } return $s; }
function mkVec(int $i): Vec { $w = new Vec(); for ($j = 0; $j < 4; $j++) { $w->push($i + $j); } return $w; }

function round_(int $i): int
{
    $n = 0;
    foreach (mkMap($i) as $k => $v) { $n += strlen($v); }
    foreach (mkSet($i) as $v) { $n += strlen($v); }
    foreach (mkVec($i) as $v) { $n += $v; }
    $n += mkMap($i)->reduce(fn($c, $v) => $c + strlen($v), 0);
    $n += mkSet($i)->reduce(fn($c, $v) => $c + strlen($v), 0);
    $n += mkVec($i)->reduce(fn($c, $v) => $c + $v, 0);
    return $n;
}

$t = 0;
for ($i = 0; $i < 200; $i++) { $t += round_($i); }
$before = memory_get_peak_usage();
for ($i = 0; $i < 20000; $i++) { $t += round_($i); }
echo $t, "\n";
echo memory_get_peak_usage() - $before < 2 << 20 ? "flat\n" : "grows\n";
