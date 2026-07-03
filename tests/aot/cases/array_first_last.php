<?php
// array_first / array_last (PHP 8.5) + array_key_first / array_key_last.
$ints  = [10, 20, 30];
$strs  = ["aa", "bb", "cc"];
$flts  = [1.5, 2.5, 3.5];
$bools = [true, false];
$assoc = ["x" => 100, "y" => 200, "z" => 300];
$het   = [1, "two", 3.0];
$one   = [42];
$empty = [];
$nested = [[1, 2], [3, 4]];

echo array_first($ints), " ", array_last($ints), "\n";
echo array_first($strs), " ", array_last($strs), "\n";
echo array_first($flts), " ", array_last($flts), "\n";
echo array_first($assoc), " ", array_last($assoc), "\n";
echo array_first($het), " ", array_last($het), "\n";
echo array_first($one), " ", array_last($one), "\n";
echo array_key_first($ints), " ", array_key_last($ints), "\n";
echo array_key_first($assoc), " ", array_key_last($assoc), "\n";
var_dump(array_first($bools));
var_dump(array_last($bools));
var_dump(array_first($empty));
var_dump(array_key_last($empty));
var_dump(array_first($nested));
var_dump(array_last($nested));

// arithmetic / comparison / interpolation on the cell result
$n = ["a" => 1, "b" => 2];
echo array_first($n) + array_last($n), "\n";
if (array_first([5, 6, 7]) === 5) { echo "strict-ok\n"; }
$k = array_key_last($n);
echo "lastkey=$k\n";
