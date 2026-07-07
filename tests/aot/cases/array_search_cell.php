<?php
// in_array / array_search on a HETEROGENEOUS (cell-value) array: compare by tag
// via ==/=== (`@param mixed[]` keeps $v a cell), not a string-only strcmp that
// faulted on a null/int value. Loose AND strict, assoc string-key results.
$a = ["x" => null, "y" => 1, "z" => 3];
var_dump(in_array(3, $a));                 // true
var_dump(in_array(99, $a));                // false
var_dump(in_array(1, $a, true));           // true (strict)
var_dump(in_array("1", $a, true));         // false (strict int != string)
var_dump(array_search(3, $a));             // "z"
var_dump(array_search(null, $a, true));    // "x"
echo array_search(20, ["p" => 10, "q" => 20]), "\n";     // q
echo (in_array("b", ["a", "b", "c"], true) ? "y" : "n"), "\n"; // y (concrete)
echo (in_array(2, [1, 2, 3]) ? "y" : "n"), "\n";               // y
