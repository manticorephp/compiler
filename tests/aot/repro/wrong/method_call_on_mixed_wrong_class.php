<?php
// A method call on a mixed object of a class without that method runs another class's method instead of throwing "Call to undefined method".
// issue: #737
class Foo { public int $n = 1; public function get(int $k): int { return $this->n + $k; } }
class Other {}
function m(mixed $v): mixed { return $v; }
try { var_dump(m(new Other())->get(1)); } catch (\Error $e) { echo $e->getMessage(), "\n"; }
