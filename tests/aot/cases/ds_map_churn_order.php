<?php
use Manticore\Ds\Map;
use Manticore\Ds\Set;

function ends(Map $m): void
{
    $ks = [];
    foreach ($m as $k => $v) { $ks[] = "$k=$v"; }
    echo count($m), ' first ', implode(',', array_slice($ks, 0, 5)), ' last ', implode(',', array_slice($ks, -5)), "\n";
}

// remove from the front while inserting at the back
/** @var Map<int,int> $m */
$m = new Map();
for ($i = 0; $i < 1000; $i++) { $m->set($i, $i * 2); }
for ($i = 0; $i < 100000; $i++) { $m->remove($i); $m->set($i + 1000, ($i + 1000) * 2); }
ends($m);
$ok = true;
for ($i = 100000; $i < 101000; $i++) { if ($m->get($i) !== $i * 2) { $ok = false; } }
for ($i = 0; $i < 100000; $i += 997) { if ($m->has($i)) { $ok = false; } }
echo $ok ? "get ok\n" : "get BAD\n";

// remove all, re-add
for ($i = 100000; $i < 101000; $i++) { $m->remove($i); }
echo count($m), "\n";
for ($i = 0; $i < 20; $i++) { $m->set($i * 3, $i); }
ends($m);

// string keys, colliding churn against an array model
/** @var Map<string,int> $s */
$s = new Map();
$model = [];
$x = 12345;
$bad = 0;
for ($i = 0; $i < 200000; $i++) {
    $x = ($x * 1103515245 + 12345) & 0x7fffffff;
    $k = 'k' . ($x % 3000);
    if (($x >> 8) % 3 === 0) {
        if ($s->has($k) !== isset($model[$k])) { $bad++; }
        if (isset($model[$k])) { $s->remove($k); unset($model[$k]); }
    } else {
        $s->set($k, $i);
        $model[$k] = $i;
    }
}
foreach ($model as $k => $v) { if ($s->get($k) !== $v) { $bad++; } }
echo count($s) === count($model) ? "len ok" : "len BAD", " bad=$bad\n";
$order = array_keys($model);
$got = [];
foreach ($s as $k => $v) { $got[] = $k; }
sort($order); sort($got);
echo $order === $got ? "keys ok\n" : "keys BAD\n";

/** @var Set<int> $st */
$st = new Set();
for ($i = 0; $i < 50000; $i++) { $st->add($i); }
for ($i = 0; $i < 50000; $i++) { $st->remove($i); }
echo count($st), "\n";
for ($i = 0; $i < 5; $i++) { $st->add($i * 11); }
echo count($st), ' ', $st->has(22) ? 'y' : 'n', $st->has(23) ? 'y' : 'n', "\n";

// fresh string keys and values through set/remove stay flat
function churn(int $n): void
{
    $m = new Map();
    for ($i = 0; $i < $n; $i++) {
        $m->set('key' . $i, str_repeat('v', 40) . $i);
        if ($i >= 16) { $m->remove('key' . ($i - 16)); }
    }
}
churn(2000);
$before = memory_get_peak_usage();
churn(300000);
echo memory_get_peak_usage() - $before < 4 << 20 ? "flat\n" : "grows\n";
