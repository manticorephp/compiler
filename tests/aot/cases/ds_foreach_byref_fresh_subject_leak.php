<?php
// A by-ref foreach over a fresh Map/Set/Vec temporary throws and releases the temporary.
use Manticore\Ds\{Map, Set, Vec};

function mkMap(int $i): Map { $m = new Map(); for ($j = 0; $j < 4; $j++) { $m->set("k$j", str_repeat('m', 200) . $i); } return $m; }
function mkSet(int $i): Set { $s = new Set(); for ($j = 0; $j < 4; $j++) { $s->add(str_repeat('s', 200) . $i . '.' . $j); } return $s; }
function mkVec(int $i): Vec { $w = new Vec(); for ($j = 0; $j < 4; $j++) { $w->push(str_repeat('w', 200) . $i); } return $w; }

function round_(int $i): int
{
    $n = 0;
    try { foreach (mkMap($i) as &$v) { $n++; } } catch (Exception $e) { $n += 1; }
    try { foreach (mkSet($i) as &$v) { $n++; } } catch (Exception $e) { $n += 1; }
    try { foreach (mkVec($i) as &$v) { $n++; } } catch (Exception $e) { $n += 1; }
    return $n;
}

$t = 0;
for ($i = 0; $i < 200; $i++) { $t += round_($i); }
$before = memory_get_peak_usage();
for ($i = 0; $i < 20000; $i++) { $t += round_($i); }
echo $t, "\n";
echo memory_get_peak_usage() - $before < 2 << 20 ? "flat\n" : "grows\n";
