<?php
// A cycle through UNTYPED properties (an object, a closure capturing one) is collected, and the strings the collected nodes held are released.
class Req { public $route; public $resolver; public $name; public function __construct(public int $id) { $this->name = str_repeat('n', 100) . $id; } public function __destruct() { global $d; $d++; } }
class Route { public $req; public $tags = []; }
$d = 0;
function direct(int $i) { $req = new Req($i); $route = new Route(); $route->req = $req; $req->route = $route; $route->tags[] = 'tag' . $i; }
function viaClosure(int $i) { $req = new Req($i); $route = new Route(); $route->req = $req; $req->resolver = fn () => $route; }
for ($i = 0; $i < 300; $i++) { direct($i); viaClosure($i); }
gc_collect_cycles();
echo "destructed $d\n";
$m0 = memory_get_peak_usage();
for ($i = 0; $i < 20000; $i++) { direct($i); viaClosure($i); if ($i % 500 === 0) { gc_collect_cycles(); } }
gc_collect_cycles();
echo ((memory_get_peak_usage() - $m0) >> 20) < 8 ? "flat\n" : "LEAK\n";
echo "end\n";
