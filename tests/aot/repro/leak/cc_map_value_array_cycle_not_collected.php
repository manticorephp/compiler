<?php
// A cycle that runs through an array of objects stored as a Manticore\Ds\Map value is not collected by gc_collect_cycles().
use Manticore\Ds\Map;
final class A { public ?Map $m = null; public string $pad; public function __construct() { $this->pad = str_repeat('x', 16384); } }
$m0 = memory_get_peak_usage();
for ($i = 0; $i < 2000; $i++) {
    $a = new A();
    $m = new Map();
    $a->m = $m;
    $m->set('list', [$a]);
    unset($a, $m);
    gc_collect_cycles();
}
$mb = (memory_get_peak_usage() - $m0) >> 20;
fwrite(STDERR, "grew {$mb} MB\n");
echo $mb < 8 ? "flat\n" : "LEAK\n";
