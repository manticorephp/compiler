<?php
// A private property or method accessed from inside a closure that is not bound to the class is not access-checked
// issue: #422
class A { private int $x = 1; private function f() { return 2; } }
$g = function (A $o) {
    try { var_dump($o->x); } catch (Error $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
    try { var_dump($o->f()); } catch (Error $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
};
$g(new A);
