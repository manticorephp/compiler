<?php
// A method call on a mixed value holding an array runs the method on the array instead of throwing Error.
// issue: #736
class Foo { public int $n = 1; public function get(int $k): int { return $this->n + $k; } }
function m(mixed $v): mixed { return $v; }
try { var_dump(m([1])->get(1)); } catch (\Error $e) { echo $e->getMessage(), "\n"; }
