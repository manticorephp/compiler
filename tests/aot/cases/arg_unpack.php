<?php
// Argument unpacking f(...$arr) into fixed-arity params (int / string / typed).
function add3($a, $b, $c) { return $a + $b + $c; }
function join2(string $a, string $b): string { return $a . "-" . $b; }
function fmt($a, $b) { return "$a=$b"; }
$nums = [1, 2, 3];
echo add3(...$nums), "\n";
echo join2(...["hi", "yo"]), "\n";
echo fmt(...[5, 10]), "\n";
