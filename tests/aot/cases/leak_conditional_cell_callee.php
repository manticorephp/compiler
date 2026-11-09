<?php
// The +1 a cell conditional callee takes on its arm is released after the call (Laravel Router::substituteImplicitBindings).
class Route { public string $pad; public function __construct() { $this->pad = str_repeat('r', 2000); } }
class Router { public $cb;
  public function viaCuf($route) { $default = fn () => strlen($route->pad); return call_user_func($this->cb ?? $default, $route); }
  public function viaInvoke($route) { $default = fn () => strlen($route->pad); return ($this->cb ?? $default)($route); } }
$r = new Router();
echo $r->viaCuf(new Route()), ' ', $r->viaInvoke(new Route()), "\n";
$m0 = memory_get_peak_usage();
for ($i = 0; $i < 20000; $i++) { $r->viaCuf(new Route()); $r->viaInvoke(new Route()); }
echo ((memory_get_peak_usage() - $m0) >> 20) < 8 ? "flat\n" : "LEAK\n";
