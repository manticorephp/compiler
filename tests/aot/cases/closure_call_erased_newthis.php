<?php
// Closure::call() with an object-typed (erased) newThis argument segfaults.
class B { public int $v = 10; }
$b = new B;
$f = function () { return $this->v + 6; };
function t(Closure $c, object $o) { return $c->call($o); }
echo t($f, $b), "\n";
