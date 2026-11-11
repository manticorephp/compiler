<?php
// A spread into a variadic method, static method or constructor that throws:
// the pack built for the call is released by the unwind.
// @serial: a memory measurement.
final class K {
    public function __construct(int ...$xs) { if (count($xs) > 100) { throw new RuntimeException('ctor'); } }
    public function boom(int ...$xs): int { throw new RuntimeException('n=' . count($xs)); }
    public static function sboom(int ...$xs): int { throw new RuntimeException('s'); }
}
/** @param int[] $a */
function viaMethod(K $k, array $a): int { return $k->boom(...$a); }
/** @param int[] $a */
function viaStatic(array $a): int { return K::sboom(...$a); }
/** @param int[] $a */
function viaNew(array $a): K { return new K(...$a); }

$k = new K();
$ints = range(1, 64);
$big = range(1, 200);
$caught = 0;
$m0 = memory_get_usage();
for ($i = 0; $i < 100000; $i++) {
    try { viaMethod($k, $ints); } catch (RuntimeException $e) { $caught++; }
    try { viaStatic($ints); } catch (RuntimeException $e) { $caught++; }
    try { viaNew($big); } catch (RuntimeException $e) { $caught++; }
}
$d = memory_get_peak_usage() - $m0;
echo $caught, "\n";
echo $d < 20000000 ? "ok" : "LEAK " . $d, "\n";
