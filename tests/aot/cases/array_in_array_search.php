<?php
// in_array / array_search synthesized per call over a concretely-typed haystack
// (int / string / assoc), strict + loose, use-as-key, miss.
$a = [10, 20, 30, 40, 50];
var_dump(in_array(30, $a));
var_dump(in_array(35, $a));
echo array_search(40, $a), "\n";          // 3
var_dump(array_search(99, $a));           // false
$k = array_search(20, $a); echo $a[$k], "\n";   // 20 (use the returned key)

$s = ["apple", "banana", "cherry"];
var_dump(in_array("banana", $s));
var_dump(in_array("grape", $s));
echo array_search("cherry", $s), "\n";    // 2

$m = ["x" => 1, "y" => 2, "z" => 3];      // assoc: search values, return string key
var_dump(in_array(2, $m));
echo array_search(3, $m), "\n";           // z

var_dump(in_array("30", $a, true));       // false: strict string-vs-int
var_dump(in_array(30, $a, true));         // true: strict int

$f = array_filter($a, fn($x) => $x > 25); // in_array over a filtered (sparse) result
var_dump(in_array(40, $f));
