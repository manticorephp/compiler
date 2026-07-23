<?php
$fn = fn($x, $y, $z) => $x * 100 + $y * 10 + $z;
$rest = [2, 3];
echo $fn(1, ...$rest), "\n";
