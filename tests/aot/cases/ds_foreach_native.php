<?php
use Manticore\Ds\{Map, Set, Vec};
$m = new Map(); foreach (['a' => 1, 'b' => 2, 'c' => 3] as $k => $v) { $m->set($k, $v); }
$m->remove('b'); $m->set('b', 22);
foreach ($m as $k => $v) { echo "$k=$v "; } echo "\n";
$s = new Set(); foreach ([3, 1, 2] as $x) { $s->add($x); }
foreach ($s as $i => $v) { echo "$i:$v "; } echo "\n";
$v = Vec::fromArray(['x', 'y']);
foreach ($v as $i => $x) { if ($i === 0) { $v->push('z'); } echo "$i=$x "; } echo "\n";
foreach ($m as $k => $_) { if ($k === 'a') { continue; } echo $k; } echo "\n";
function bound(): void
{
    /** @var Map<string,int> $bm */
    $bm = new Map();
    $bm->set('p', 1); $bm->set('q', 2);
    foreach ($bm as $k => $x) { echo $k, $x + 1, ' '; }
    /** @var Set<int> $bs */
    $bs = new Set();
    $bs->add(5); $bs->add(7);
    foreach ($bs as $i => $x) { echo $i, ':', $x + 1, ' '; }
    /** @var Vec<int> $bv */
    $bv = new Vec();
    $bv->push(3); $bv->push(4);
    foreach ($bv as $i => $x) { echo $i, ':', $x * 2, ' '; }
    echo "\n";
}
bound();
function nulls(?Map $m, ?Set $s, ?Vec $w): void
{
    foreach ($m as $k => $x) { echo $k, $x; }
    foreach ($s as $x) { echo $x; }
    foreach ($w as $i => $x) { echo $i, $x; }
    echo "nulls walk nothing\n";
}
nulls(null, null, null);
