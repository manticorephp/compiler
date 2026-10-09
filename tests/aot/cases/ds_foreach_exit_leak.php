<?php
use Manticore\Ds\{Map, Set, Vec};

function roundMap(int $i): int
{
    $m = new Map();
    for ($j = 0; $j < 4; $j++) { $m->set("k$j", str_repeat('v', 200) . $i . '.' . $j); }
    $n = 0;
    foreach ($m as $k => $v) { $n += strlen($v); break; }
    try {
        foreach ($m as $k => $v) { if ($k === 'k1') { throw new RuntimeException($v); } }
    } catch (RuntimeException $e) { $n += strlen($e->getMessage()); }
    foreach ($m as $k => $v) {
        for ($j = 0; $j < 3; $j++) { if ($j === 1) { continue 2; } $n += strlen($v); }
    }
    $c = clone $m;
    try {
        foreach ($c as $k => $v) { $c->clear(); }
    } catch (RuntimeException $e) { $n += strlen($e->getMessage()); }
    foreach ($m as $k => $v) { if ($k === 'k2') { return $n + strlen($v); } }
    return -1;
}

function roundSet(int $i): int
{
    $s = new Set();
    for ($j = 0; $j < 4; $j++) { $s->add(str_repeat('s', 200) . $i . '.' . $j); }
    $n = 0;
    foreach ($s as $k => $v) { $n += strlen($v); break; }
    try {
        foreach ($s as $k => $v) { if ($k === 1) { throw new RuntimeException($v); } }
    } catch (RuntimeException $e) { $n += strlen($e->getMessage()); }
    foreach ($s as $v) {
        for ($j = 0; $j < 3; $j++) { if ($j === 1) { continue 2; } $n += strlen($v); }
    }
    $c = clone $s;
    try {
        foreach ($c as $v) { $c->clear(); }
    } catch (RuntimeException $e) { $n += strlen($e->getMessage()); }
    foreach ($s as $k => $v) { if ($k === 2) { return $n + strlen($v); } }
    return -1;
}

function roundVec(int $i): int
{
    $w = new Vec();
    for ($j = 0; $j < 4; $j++) { $w->push(str_repeat('w', 200) . $i . '.' . $j); }
    $n = 0;
    foreach ($w as $k => $v) { $n += strlen($v); break; }
    try {
        foreach ($w as $k => $v) { if ($k === 1) { throw new RuntimeException($v); } }
    } catch (RuntimeException $e) { $n += strlen($e->getMessage()); }
    foreach ($w as $v) {
        for ($j = 0; $j < 3; $j++) { if ($j === 1) { continue 2; } $n += strlen($v); }
    }
    foreach ($w as $k => $v) { if ($k === 2) { return $n + strlen($v); } }
    return -1;
}

$t = 0;
for ($i = 0; $i < 200; $i++) { $t += roundMap($i) + roundSet($i) + roundVec($i); }
$before = memory_get_peak_usage();
for ($i = 0; $i < 10000; $i++) { $t += roundMap($i) + roundSet($i) + roundVec($i); }
echo $t, "\n";
echo memory_get_peak_usage() - $before < 2 << 20 ? "flat\n" : "grows\n";
