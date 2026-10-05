<?php
// getTraceAsString omits call arguments in frames: "A->m()" where php prints "A->m(1)".
// issue: #79
class A { public function m(int $n) { throw new Exception("x"); } }
function chain(int $n) { (new A)->m($n); }
try { chain(7); } catch (Exception $e) { echo $e->getTraceAsString(), "\n"; }
