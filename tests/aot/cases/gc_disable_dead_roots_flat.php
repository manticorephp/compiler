<?php
// With automatic collection off, a root candidate that dies is freed at once, not left in the root buffer.
class Node { public $v; public function __construct(int $v) { $this->v = $v; } }
class Holder { public ?Node $n = null; }
function churn(Holder $h, int $n): void {
    for ($i = 0; $i < $n; $i++) {
        $a = new Node($i);
        $h->n = $a;   // rc 2
        $h->n = null; // rc 1: buffered as a possible cycle root
        unset($a);    // rc 0: leaves the buffer, freed now
    }
}
if ($argc > 99) { gc_collect_cycles(); } // links the collector
gc_disable();
$h = new Holder;
churn($h, 2000);
$before = memory_get_usage();
churn($h, 1000000);
$after = memory_get_usage();
echo ($after - $before) < 8 * 1024 * 1024 ? "flat\n" : "grew\n";
