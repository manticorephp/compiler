<?php
$fn = fn($a, $b, $c) => $a + $b + $c;
$args = [1, 2, 3];
echo $fn(...$args), "\n";
$s = fn(string $x, string $y) => $x . "-" . $y;
$sa = ["hi", "yo"];
echo $s(...$sa), "\n";
