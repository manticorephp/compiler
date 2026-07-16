<?php
// PHP's loose-comparison table. Row order is load-bearing: null-vs-string
// compares NULL as "" and must outrank the bool row — `null == "0"` is FALSE,
// while the bool row would call "0" falsy and answer true.
var_dump(null == false, null == 0, null == "", null == "0", null == "a");
var_dump(0 == false, "" == false, "a" == true, "0" == false, [] == false);
var_dump(null == [], null == [1], [1] == true);

// Two numeric strings compare NUMERICALLY, other strings byte-wise.
var_dump("1.0" == "1", "1e2" == "100", " 1" == "1", "1 " == "1", "10" == "1e1");
var_dump("abc" == "abd", "abc" == "abc", "10" == "9");
var_dump("1.0" === "1", "abc" == 0);

// An array is greater than any non-array; an object outranks even an array.
var_dump([1] == 1, [1] == "1");

// The same table at runtime, through erased (cell) operands.
function o(mixed $v): mixed { return $v; }
$n = o(null); $f = o(false); $e = o(""); $t = o("a"); $a = o([]); $s = o("1.0");
var_dump($n == $f, $n == $e, $e == $f, $t == true, $a == $f, $s == "1");
var_dump($n == null, $t == null, $a == null);

// switch matches with `==`, so it juggles the same way.
$x = "10";
switch ($x) { case 10: echo "SW-NUM\n"; break; default: echo "SW-DEF\n"; }
$y = "a";
switch ($y) { case true: echo "SW-TRUE\n"; break; default: echo "SW-DEF2\n"; }

// Ordering juggles too.
var_dump(0 < "1", "abc" < "abd", "" < "a", "Z" < "a");
