<?php

// A slid packed array still owns what it holds: the shifted-out element goes to
// the caller, the rest die with the buffer, in order, and a long-running queue
// stays flat. @serial: a memory measurement.
class D
{
    public function __construct(public string $n) {}
    public function __destruct() { echo "~", $this->n, " "; }
}

function objs(): void
{
    $q = [new D('a'), new D('b'), new D('c'), new D('d')];
    $x = array_shift($q);
    echo "got ", $x->n, " ";
    $x = null;
    array_unshift($q, new D('z'));
    echo "count ", count($q), " ";
    while ($q) { $x = array_shift($q); echo $x->n, " "; $x = null; }
    echo "\n";
}
objs();
echo "after\n";

function queue(int $rounds): int
{
    $q = [];
    $t = 0;
    for ($i = 0; $i < $rounds; $i++) {
        $q[] = str_repeat('x', 64) . $i;
        $q[] = [$i, (string)$i];
        if (count($q) > 40) { array_shift($q); $v = array_shift($q); $t += is_array($v) ? 1 : 0; }
        if ($i % 7 === 0) { array_unshift($q, 'u' . $i); }
    }
    while ($q) { array_shift($q); }
    return $t;
}
queue(1000);
$m0 = memory_get_usage();
$r = queue(300000);
echo $r, " ", (memory_get_peak_usage() - $m0) < 20000000 ? "flat" : "LEAK " . (memory_get_peak_usage() - $m0), "\n";
