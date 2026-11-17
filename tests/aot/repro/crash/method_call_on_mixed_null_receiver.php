<?php
// A method call on a mixed value holding null segfaults instead of throwing "Call to a member function get() on null".
// issue: #738
class Foo { public int $n = 1; public function get(int $k): int { return $this->n + $k; } }
function m(mixed $v): mixed { return $v; }
try { var_dump(m(null)->get(1)); } catch (\Error $e) { echo $e->getMessage(), "\n"; }
