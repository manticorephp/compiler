<?php
use Manticore\Ds\Map;

final class Box { public function __construct(public string $s) {} }
function churn(int $n): void
{
    $m = new Map();
    for ($i = 0; $i < $n; $i++) {
        $m->set("k$i", new Box(str_repeat('x', 64)));
        $kb = new Box('key'); $m->set($kb, $i); $m->remove($kb);
        if ($i >= 8) { $m->remove('k' . ($i - 8)); }
        if (($i & 1023) === 0) { $c = clone $m; $c->clear(); }
    }
}
churn(2000);
$before = memory_get_peak_usage();
churn(100000);
echo memory_get_peak_usage() - $before < 4 << 20 ? "flat\n" : "grows\n";
