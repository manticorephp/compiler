<?php
// A function named at run time, called with a spread into its variadic
// parameter: the pack rebuilt for each call is released, on return and on throw.
// @serial: a memory measurement.
function sumv(int ...$xs): int { return count($xs); }
function boom(int ...$xs): int { throw new RuntimeException('n=' . count($xs)); }
function callI(string $f, int ...$a): mixed { return $f(...$a); }
/** @param int[] $a */
function callN(string $f, array $a): mixed { return $f(...$a); }

$ints = range(1, 64);
$s = $argc > 9 ? '' : 'sumv';
$b = $argc > 9 ? '' : 'boom';
$t = 0;
$m0 = memory_get_usage();
for ($i = 0; $i < 100000; $i++) {
    $t += callN($s, $ints) + callI($s, ...$ints);
    try { callN($b, $ints); } catch (RuntimeException $e) { $t++; }
    try { callI($b, ...$ints); } catch (RuntimeException $e) { $t++; }
}
$d = memory_get_peak_usage() - $m0;
echo $t, "\n";
echo $d < 20000000 ? "ok" : "LEAK " . $d, "\n";
