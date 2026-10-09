<?php
// Map/Set vs PHP arrays: insert + lookup + remove, foreach, reduce. P2 (1e6, ms, arm64 macOS): see docs/ds.md "Cost".
//   bin/manticore compile tools/bench/ds_map_bench.php -o /tmp/ds_map_bench   (run under an RSS watchdog)
use Manticore\Ds\Map;
use Manticore\Ds\Set;

$n = (int) ($argv[1] ?? 1000000);

function report(string $name, int $t0, int $sum): void
{
    printf("%-22s %6d ms  peak %5d MB  (check %d)\n", $name, intdiv(hrtime(true) - $t0, 1000000), intdiv(memory_get_peak_usage(), 1048576), $sum);
}

$t = hrtime(true); $a = []; $s = 0;
for ($i = 0; $i < $n; $i++) { $a["k$i"] = $i; }
for ($i = 0; $i < $n; $i++) { $s += $a["k$i"]; }
for ($i = 0; $i < $n; $i++) { unset($a["k$i"]); }
report('array<string,int>', $t, $s + count($a)); $a = [];

$t = hrtime(true);
/** @var Map<string,int> $m */
$m = new Map(); $s = 0;
for ($i = 0; $i < $n; $i++) { $m->set("k$i", $i); }
for ($i = 0; $i < $n; $i++) { $s += $m->get("k$i"); }
for ($i = 0; $i < $n; $i++) { $m->remove("k$i"); }
report('Map<string,int>', $t, $s + count($m)); $m = null;

$t = hrtime(true); $a = []; $s = 0;
for ($i = 0; $i < $n; $i++) { $a[$i * 7] = $i; }
for ($i = 0; $i < $n; $i++) { $s += $a[$i * 7]; }
for ($i = 0; $i < $n; $i++) { unset($a[$i * 7]); }
report('array<int,int>', $t, $s + count($a)); $a = [];

$t = hrtime(true);
/** @var Map<int,int> $m */
$m = new Map(); $s = 0;
for ($i = 0; $i < $n; $i++) { $m->set($i * 7, $i); }
for ($i = 0; $i < $n; $i++) { $s += $m->get($i * 7); }
for ($i = 0; $i < $n; $i++) { $m->remove($i * 7); }
report('Map<int,int>', $t, $s + count($m)); $m = null;

$objs = [];
for ($i = 0; $i < $n; $i++) { $objs[] = new stdClass(); }

$t = hrtime(true); $a = []; $s = 0;
foreach ($objs as $o) { $a[spl_object_id($o)] = true; }
foreach ($objs as $o) { if (isset($a[spl_object_id($o)])) { $s++; } }
foreach ($objs as $o) { unset($a[spl_object_id($o)]); }
report('array<id,true>', $t, $s + count($a)); $a = [];

$t = hrtime(true);
/** @var Set<stdClass> $set */
$set = new Set(); $s = 0;
foreach ($objs as $o) { $set->add($o); }
foreach ($objs as $o) { if ($set->has($o)) { $s++; } }
foreach ($objs as $o) { $set->remove($o); }
report('Set<object>', $t, $s + count($set));

/** @var Map<int,int> $m */
$m = new Map(); for ($i = 0; $i < $n; $i++) { $m->set($i, $i); }
$t = hrtime(true); $s = 0;
foreach ($m as $k => $v) { $s += $v; }
report('foreach Map<int,int>', $t, $s);
$a = []; for ($i = 0; $i < $n; $i++) { $a[$i] = $i; }
$t = hrtime(true); $s = 0;
foreach ($a as $k => $v) { $s += $v; }
report('foreach array<int,int>', $t, $s); $m = null; $a = [];

/** @var Map<int,int> $m2 */
$m2 = new Map(); for ($i = 0; $i < $n; $i++) { $m2->set($i, $i); }
$t = hrtime(true);
$s = $m2->reduce(fn($c, $v) => $c + $v, 0);
report('reduce literal (fused)', $t, $s);
$f = fn($c, $v) => $c + $v;
$t = hrtime(true);
$s = $m2->reduce($f, 0);
report('reduce $f', $t, $s);
$t = hrtime(true);
$s = $m2->reduce(fn(int $c, int $v, int $k): int => $c + $v, 0);
report('reduce typed (not fused)', $t, $s);
