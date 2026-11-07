<?php
// $erased->m(...func_get_args()) / (...$pack) over many unrelated classes with different params.
class P0 { public function m($a): string { return "P0.m(" . json_encode(func_get_args()) . ")"; } public function z() { return "P0.z"; } }
class P1 { public function m($a, $b = "d1"): string { return "P1.m(" . json_encode(func_get_args()) . ")"; } public function z() { return "P1.z"; } }
class P2 { public function m(int $a, string $b = "v"): string { return "P2.m(" . json_encode(func_get_args()) . ")"; } public function z() { return "P2.z"; } }
class P3 { public function m($a): string { return "P3.m(" . json_encode(func_get_args()) . ")"; } public function z() { return "P3.z"; } }
class P4 { public function m($a, $b = "d4"): string { return "P4.m(" . json_encode(func_get_args()) . ")"; } public function z() { return "P4.z"; } }
class P5 { public function m(int $a, string $b = "v"): string { return "P5.m(" . json_encode(func_get_args()) . ")"; } public function z() { return "P5.z"; } }
class P6 { public function m($a): string { return "P6.m(" . json_encode(func_get_args()) . ")"; } public function z() { return "P6.z"; } }
class P7 { public function m($a, $b = "d7"): string { return "P7.m(" . json_encode(func_get_args()) . ")"; } public function z() { return "P7.z"; } }
class P8 { public function m(int $a, string $b = "v"): string { return "P8.m(" . json_encode(func_get_args()) . ")"; } public function z() { return "P8.z"; } }
class P9 { public function m($a): string { return "P9.m(" . json_encode(func_get_args()) . ")"; } public function z() { return "P9.z"; } }
class Q { public function __call($n, $a) { return "Q.__call($n," . json_encode($a) . ")"; } }
class Proxy { private $t; public function __construct($t) { $this->t = $t; } public function init() { return $this->t; }
  public function m() { return $this->init()->m(...\func_get_args()); }
  public function viaPack($o, array $p) { return $o->m(...$p); } }
foreach (range(0, 9) as $i) { $c = "P$i"; $p = new Proxy(new $c); try { echo $p->m(1), " ", $p->viaPack(new $c, [3]), "\n"; } catch (\Throwable $e) { echo get_class($e), "\n"; } }
try { echo (new Proxy(new P0))->m(), "\n"; } catch (\Throwable $e) { echo get_class($e), "\n"; }
