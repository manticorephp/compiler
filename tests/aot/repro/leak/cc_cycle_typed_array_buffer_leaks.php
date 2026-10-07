<?php
// A typed array (Int32Array) held by an object in a cycle leaks its buffer when gc_collect_cycles() collects the cycle.
use Manticore\Ds\Int32Array;
final class Holder { public ?Holder $self = null; public Int32Array $buf; public function __construct() { $this->buf = new Int32Array(4096); } }
$m0 = memory_get_peak_usage();
for ($i = 0; $i < 2000; $i++) {
    $h = new Holder();
    $h->self = $h;
    unset($h);
    gc_collect_cycles();
}
$mb = (memory_get_peak_usage() - $m0) >> 20;
fwrite(STDERR, "grew {$mb} MB\n");
echo $mb < 8 ? "flat\n" : "LEAK\n";
