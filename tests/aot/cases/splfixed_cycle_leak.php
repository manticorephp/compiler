<?php
final class Holder { public ?SplFixedArray $a = null; }
function cycles(int $n): void
{
    for ($i = 0; $i < $n; $i++) {
        $h = new Holder(); $h->a = new SplFixedArray(2); $h->a[0] = $h; $h->a[1] = str_repeat('x', 64);
    }
    gc_collect_cycles();
}
cycles(1000);
$before = memory_get_peak_usage();
cycles(100000);
echo memory_get_peak_usage() - $before < 4 << 20 ? "flat\n" : "grows\n";
