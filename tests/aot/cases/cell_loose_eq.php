<?php
// == / === on two cells with PHP juggling: numeric strings compare numerically
// (5 == "5", "10" == "1e1"), non-numeric strings byte-wise, non-interned strings
// by value, and === stays type-strict. Was a raw-bit compare (only interned
// strings / canonical ints accidentally worked).
function eq(mixed $x, mixed $y): bool  { return $x == $y; }
function seq(mixed $x, mixed $y): bool { return $x === $y; }
var_dump(eq(5, "5"));      // true
var_dump(eq(5, "5.0"));    // true
var_dump(eq(5, "5abc"));   // false
var_dump(eq(0, "a"));      // false
var_dump(eq("10", "1e1")); // true
var_dump(eq(null, 0));     // true
var_dump(seq(5, "5"));     // false
$s = "ab" . "cd";
var_dump(seq($s, "abcd")); // true (non-interned)
var_dump(in_array(5, ["a" => 1, "b" => "5"]));         // true
echo array_search("10", ["x" => 1, "y" => "1e1"]), "\n"; // y
