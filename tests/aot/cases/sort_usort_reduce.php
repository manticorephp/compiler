<?php
// Prelude-injected callback/sort array functions. NOTE: a given sort function's
// call sites must share ONE element type (the call-site element inference is
// all-agree; mixed types per function need monomorphization). Here sort/rsort
// take int lists and usort takes string lists.
$nums = [5, 3, 8, 1, 9, 2, 7];
sort($nums);
echo implode(",", $nums), "\n";
rsort($nums);
echo implode(",", $nums), "\n";

$words = ["pear", "apple", "kiwi", "fig"];
usort($words, fn($x, $y) => strcmp($x, $y));
echo implode(",", $words), "\n";

$byLen = ["ccc", "a", "dddd", "bb"];
usort($byLen, fn($x, $y) => strlen($x) - strlen($y));
echo implode(",", $byLen), "\n";

echo array_reduce([1, 2, 3, 4, 5], fn($c, $x) => $c + $x, 0), "\n";
echo array_reduce([10, 20, 30], fn($c, $x) => $c > $x ? $c : $x, 0), "\n";
