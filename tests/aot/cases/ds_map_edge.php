<?php
use Manticore\Ds\Map;
use Manticore\Ds\Set;

$m = new Map();
$big = [PHP_INT_MAX, PHP_INT_MIN, 1 << 47, -(1 << 47), (1 << 47) - 1, -(1 << 47) - 1, 1 << 62, PHP_INT_MAX - 1, 0, -1];
foreach ($big as $i => $k) { $m->set($k, $i); }
$m->set(PHP_INT_MAX, 100);
echo count($m), "\n";
foreach ($big as $i => $k) { echo $m->get($k), ' '; }
echo "\n";
$m->remove(PHP_INT_MIN);
echo $m->has(PHP_INT_MIN) ? 'y' : 'n', $m->has(PHP_INT_MAX) ? 'y' : 'n', count($m), "\n";

// Small table (16 slots): dense delete/reinsert forces wrap-around shifts.
$s = new Set();
$live = [];
for ($round = 0; $round < 200; $round++) {
    for ($i = 0; $i < 9; $i++) { $k = ($round * 7 + $i * 13) % 31; $s->add($k); $live[$k] = true; }
    for ($i = 0; $i < 6; $i++) { $k = ($round * 5 + $i * 11) % 31; if ($s->has($k)) { $s->remove($k); } unset($live[$k]); }
    $bad = 0;
    for ($k = 0; $k < 31; $k++) { if ($s->has($k) !== isset($live[$k])) { $bad++; } }
    if ($bad !== 0 || count($s) !== count($live)) { echo "MISMATCH round $round\n"; break; }
}
echo count($s), ' ', array_sum($s->toArray()), "\n";
