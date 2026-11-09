<?php
// A closure returned under the +1 convention and invoked through call_user_func() is released after the call (Laravel Request::route()).
class Route { public string $pad; public function __construct() { $this->pad = str_repeat('r', 2000); } public function __destruct() { global $d; $d++; } }
class Req { protected $routeResolver;
  public function setRouteResolver(Closure $callback) { $this->routeResolver = $callback; return $this; }
  /** @return \Closure */
  public function getRouteResolver() { return $this->routeResolver ?: function () { }; }
  public function route($param = null, $default = null) { $route = call_user_func($this->getRouteResolver()); if (is_null($route) || is_null($param)) { return $route; } return $route; } }
$d = 0;
function one() { $req = new Req(); $route = new Route(); $req->setRouteResolver(fn () => $route); $r = $req->route(); $r2 = $req->route(); return $r === $r2 ? 1 : 0; }
$k = 0; for ($i = 0; $i < 3000; $i++) { $k += one(); }
echo "k=$k d=$d\n";
