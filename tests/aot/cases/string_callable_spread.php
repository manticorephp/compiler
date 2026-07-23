<?php
function mul($a, $b) { return $a * $b; }
function pick() { return "mul"; }
$f = pick();
$args = [6, 7];
echo $f(...$args), "\n";
