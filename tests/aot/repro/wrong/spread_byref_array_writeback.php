<?php
// A by-ref parameter reached through a spread of an array passed by reference does not write back
// issue: #511
class A { public function inc(&$x) { $x = $x + 1; return $x; } }
function direct(A $o, array &$args) { return $o->inc(...$args); }
function dynamic(object $o, string $m, array &$args) { return $o->$m(...$args); }
$a = [10]; echo direct(new A, $a), " ", $a[0], "\n";
$b = [10]; echo dynamic(new A, "inc", $b), " ", $b[0], "\n";
