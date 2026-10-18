<?php
// Sibling of array_shift_cell_result_compared_leaks: pop/shift results only compared. @serial: a memory measurement.
function f(int $n): int
{
    $q = [];
    $t = 0;
    for ($i = 0; $i < $n; $i++) {
        $q[] = str_repeat('x', 64) . $i;
        $q[] = [$i];
        $q[] = [$i, 'k' . $i];
        $q[] = str_repeat('y', 64) . $i;
        if (array_pop($q) !== null) { $t++; }
        if (array_shift($q) == 5) { $t++; }
        if (array_pop($q) === 0) { $t--; }
        if (!array_shift($q)) { $t++; }
    }
    return $t;
}
f(1000);
$m0 = memory_get_peak_usage();
f(300000);
echo (memory_get_peak_usage() - $m0) < 4000000 ? "flat" : "LEAK", "\n";
class D { public function __construct(public string $n) {} public function __destruct() { echo "~", $this->n, " "; } }
function g(): void { $q = [new D('a'), 1, new D('b')]; if (array_shift($q) === null) { echo "n"; } echo "x "; if (array_pop($q) === null) { echo "n"; } echo "y\n"; }
g();
