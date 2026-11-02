<?php
// A by-ref parameter reached through a spread in a DYNAMIC method call does not write back
class A { public function inc(&$x) { $x = $x + 1; return $x; } }
function dynamic(object $o, string $m, array &$args) { return $o->$m(...$args); }
$b = [10]; echo dynamic(new A, "inc", $b), " ", $b[0], "\n";
