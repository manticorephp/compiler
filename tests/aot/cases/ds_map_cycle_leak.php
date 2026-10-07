<?php
use Manticore\Ds\Map;
use Manticore\Ds\Set;

final class Holder { public ?Map $m = null; }
function cycles(int $n): void
{
    for ($i = 0; $i < $n; $i++) {
        $h = new Holder(); $h->m = new Map(); $h->m->set('self', $h);
        $s = new Set(); $o = new Holder(); $o->m = new Map(); $o->m->set('s', $s); $s->add($o);
    }
    gc_collect_cycles();
}
cycles(1000);
$before = memory_get_peak_usage();
cycles(100000);
echo memory_get_peak_usage() - $before < 4 << 20 ? "flat\n" : "grows\n";
