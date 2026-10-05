<?php
// func_num_args() answers the padded arity (2) through a first-class callable and __invoke; php answers the supplied count (1)
// issue: #73
// item: 9
class Q {
    function f($a, $b = 2) { return func_num_args(); }
    function __invoke($a, $b = 2) { return func_num_args(); }
}
$r = (new Q)->f(...);
echo $r(1), "\n";
$q = new Q;
echo $q(1), "\n";
