<?php
// Baseline for Manticore\Ds Map/Set (P1, erased): insert + lookup + remove vs PHP arrays.
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

$t = hrtime(true); $m = new Map(); $s = 0;
for ($i = 0; $i < $n; $i++) { $m->set("k$i", $i); }
for ($i = 0; $i < $n; $i++) { $s += $m->get("k$i"); }
for ($i = 0; $i < $n; $i++) { $m->remove("k$i"); }
report('Map<string,int>', $t, $s + count($m)); $m = null;

$t = hrtime(true); $a = []; $s = 0;
for ($i = 0; $i < $n; $i++) { $a[$i * 7] = $i; }
for ($i = 0; $i < $n; $i++) { $s += $a[$i * 7]; }
for ($i = 0; $i < $n; $i++) { unset($a[$i * 7]); }
report('array<int,int>', $t, $s + count($a)); $a = [];

$t = hrtime(true); $m = new Map(); $s = 0;
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

$t = hrtime(true); $set = new Set(); $s = 0;
foreach ($objs as $o) { $set->add($o); }
foreach ($objs as $o) { if ($set->has($o)) { $s++; } }
foreach ($objs as $o) { $set->remove($o); }
report('Set<object>', $t, $s + count($set));
