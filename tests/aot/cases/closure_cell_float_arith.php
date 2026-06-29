<?php
// A closure's untyped (cell) params doing `$c + $x` must promote to float at
// runtime when either operand is a float — array_reduce's fold carry. Values are
// chosen exact in binary so the precision-14 cell var_dump prints them fully.
$sum = array_reduce([0.5, 0.25, 0.125], fn($c, $x) => $c + $x, 0.0);
var_dump($sum);
$prod = array_reduce([2.0, 1.5, 4.0], fn($c, $x) => $c * $x, 1.0);
var_dump($prod);
// An all-int fold stays integer.
$isum = array_reduce([3, 4, 5], fn($c, $x) => $c + $x, 0);
var_dump($isum);
