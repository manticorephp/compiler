<?php
// A mixed value stored into an `array` property is type-checked without leaking it (one copy per store leaked).
final class H { public array $p = []; public mixed $m = null; }
function mk(int $i): mixed { return [$i, str_repeat('z', 40)]; }
function run(H $h, int $i): void {
    $h->p = mk($i);
    $x = mk($i); $h->p = $x;
    $h->m = mk($i); $h->p = $h->m;
}
$h = new H;
for ($i = 1; $i <= 300; $i++) { run($h, $i); }
$m0 = memory_get_peak_usage();
for ($i = 1; $i <= 20000; $i++) { run($h, $i); }
echo (memory_get_peak_usage() - $m0) >> 20 < 2 ? "flat\n" : "LEAK\n";
$h->m = 'x';
try { $h->p = $h->m; } catch (TypeError $e) { echo $e->getMessage(), "\n"; }
