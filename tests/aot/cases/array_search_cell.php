<?php
// in_array / array_search on a HETEROGENEOUS (cell-value) array: compare by tag
// via ==/===, not a string-only strcmp that faulted on a null/int value (was a
// SIGSEGV). Loose comparison + assoc string-key results.
$a = ["x" => null, "y" => 1, "z" => 3];
var_dump(in_array(3, $a));                 // true
var_dump(in_array(99, $a));                // false
var_dump(array_search(3, $a));             // "z" (string key from an assoc)
var_dump(array_search(null, $a));          // "x"
echo array_search(20, ["p" => 10, "q" => 20]), "\n";     // q
echo (in_array("b", ["a", "b", "c"]) ? "y" : "n"), "\n"; // y (concrete synthesis path)
echo (in_array(2, [1, 2, 3]) ? "y" : "n"), "\n";         // y (concrete int)
