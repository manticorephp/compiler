<?php
// A by-ref closure parameter reached through an erased `callable`: the
// caller's variable is shared, whatever kind the callee leaves in it.
function run(callable $cb) { $v = null; $v = 1; $cb($v); var_dump($v); }
function runArr(callable $cb) { $v = null; $v = ['a']; $cb($v); var_dump($v); }
run(function (&$x) { $x = "s$x"; });
run(fn (&$x) => $x++);
runArr(function (array &$a) { $a[] = 'b'; });
runArr(function (&$a) { $a = 5; });
function cufa() {
    $n = 1;
    call_user_func_array(fn (&$x) => $x = [$x], [&$n]);
    var_dump($n);
    $s = 'a';
    call_user_func_array(function (&$x, $y) { $x .= $y; }, [&$s, 'b']);
    var_dump($s);
}
cufa();
$m = ['x', 'y'];
array_walk($m, function (&$v, $k) { $v = $v . $k; });
var_dump($m);
