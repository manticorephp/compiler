<?php
// A cycle through a closure's capture (object -> closure property -> captured object) is not collected by gc_collect_cycles().
// issue: #246
class Req
{
    public array $pad = [1, 2, 3, 4, 5, 6, 7, 8];
    private ?\Closure $factory = null;
    public function setFactory(\Closure $f): void { $this->factory = $f; }
}
$freed = 0;
$base = 0;
for ($i = 0; $i < 100000; $i++) {
    $r = new Req();
    $r->setFactory(function () use ($r) { return $r; });
    unset($r);
    if ($i % 1000 === 999) { $freed += gc_collect_cycles(); }
    if ($i === 1000) { $base = memory_get_peak_usage(); }
}
echo $freed > 0 ? "collected\n" : "not collected\n";
echo memory_get_peak_usage() - $base < 3000000 ? "flat\n" : "grows\n";
