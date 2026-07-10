<?php
$c3 = fn($a) => fn($b) => fn($c) => $a + $b + $c;
echo $c3(1)(2)(3), "\n";

$c4 = fn($a) => fn($b) => fn($c) => fn($d) => $a . $b . $c . $d;
echo $c4("w")("x")("y")("z"), "\n";

// partial application held in vars
$curry = fn($a) => fn($b) => fn($c) => $a * 100 + $b * 10 + $c;
$p1 = $curry(1);
$p2 = $p1(2);
echo $p2(3), "\n";

// mixed capture + param
$mul = fn($factor) => fn($x) => $x * $factor;
$double = $mul(2);
$triple = $mul(3);
echo $double(5), " ", $triple(5), "\n";
