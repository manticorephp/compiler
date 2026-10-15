<?php
class A { function f($a, $b = 2) { return 0; } static function s($a, $b = 2) { return 0; } }
class B extends A {
    function f($a, $b = 2) { return func_num_args(); }
    static function s($a, $b = 2) { return func_num_args(); }
}
class C { function f($a, $b = 2) { return func_num_args(); } }
function run(A $o) { $r = $o->f(...); return $r(1); }
echo run(new A), run(new B), "\n";
$r = (new C)->f(...);
echo $r(1), $r(1, 2), "\n";
$s = B::s(...);
echo $s(1), "\n";
