<?php
// The union of two `??` cells, in a ternary arm or stored in a local, is a fresh +1 (Laravel RouteCollection::get).
final class C { public array $d = []; public array $r = [];
  public function __construct() { for ($i = 0; $i < 200; $i++) { $this->r['GET']['k' . $i] = $i; } }
  public function viaArm($m = null) { return is_null($m) ? [] : ($this->d[$m] ?? []) + ($this->r[$m] ?? []); }
  public function viaLocal($m = null) { $x = ($this->d[$m] ?? []) + ($this->r[$m] ?? []); return $x; } }
function run(C $c): int { return count($c->viaArm('GET')) + count($c->viaLocal('GET')); }
$c = new C();
echo run($c), "\n";
$m0 = memory_get_peak_usage();
for ($i = 0; $i < 20000; $i++) { run($c); }
echo ((memory_get_peak_usage() - $m0) >> 20) < 8 ? "flat\n" : "LEAK\n";
