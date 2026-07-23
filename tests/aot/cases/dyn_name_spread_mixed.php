<?php
function trip($a, $b, $c) { return $a * 100 + $b * 10 + $c; }
function pick() { return "trip"; }
$f = pick();
$rest = [2, 3];
echo $f(1, ...$rest), "\n";
