<?php
// Uniform closure ABI: scalars (string/float/int/bool) travel through a closure
// as tagged cells, so a string arg/return no longer leaks as a raw pointer —
// for both direct and dynamic (`callable`) dispatch.

// Direct: string param + string return.
$ex = fn($x) => $x . "!";
echo $ex("hi"), "\n";

// Direct: float and int.
$half = fn($x) => $x / 2;
echo $half(7), "\n";
$dbl = fn($x) => $x * 2;
echo $dbl(21), "\n";

// Identity of a string through a closure.
$id = fn($x) => $x;
echo $id("kept"), "\n";

// Dynamic dispatch through a `callable` param, string return.
function apply2(callable $f, $a, $b): mixed { return $f($a, $b); }
echo apply2(fn($a, $b) => $a . "-" . $b, "L", "R"), "\n";

// array_reduce with a string-concatenating callback (prelude, dynamic).
echo array_reduce(["a", "b", "c", "d"], fn($c, $x) => $c . $x, "="), "\n";

// array_map producing strings (dynamic).
echo implode(",", array_map(fn($s) => strtoupper($s) . "!", ["ab", "cd"])), "\n";

// usort with strcmp (dynamic, int result used in a comparison).
$words = ["pear", "apple", "fig", "kiwi"];
usort($words, fn($a, $b) => strcmp($a, $b));
echo implode(",", $words), "\n";

// Captured string + string arg.
$pre = "x:";
$tag = fn($v) => $pre . $v;
echo $tag("y"), "\n";
