<?php
function counter() {
    $n = 0;
    return function() use (&$n) { $n = $n + 1; return $n; };
}
$c = counter();
echo $c(), $c(), $c(), "\n";
$d = counter();
echo $d(), "\n";
echo $c(), "\n";

function make_acc() {
    $sum = 0;
    return function(int $x) use (&$sum) { $sum = $sum + $x; return $sum; };
}
$acc = make_acc();
echo $acc(10), " ", $acc(5), " ", $acc(100), "\n";
