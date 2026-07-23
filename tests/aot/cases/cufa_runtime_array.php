<?php
function add($a, $b) { return $a + $b; }
$args = [3, 4];
echo call_user_func_array("add", $args), "\n";
$mul = fn($a, $b) => $a * $b;
$m = [6, 7];
echo call_user_func_array($mul, $m), "\n";
