<?php
use Manticore\Ds\Vec;

final class Holder { public ?Vec $v = null; }
function cycles(int $n): void
{
    for ($i = 0; $i < $n; $i++) {
        $h = new Holder(); $h->v = new Vec(); $h->v->push($h);
    }
    gc_collect_cycles();
}
cycles(1000);
$before = memory_get_peak_usage();
cycles(100000);
echo memory_get_peak_usage() - $before < 4 << 20 ? "flat\n" : "grows\n";
