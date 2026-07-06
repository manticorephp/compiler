<?php
// Untyped `array` param reading a heterogeneous vec[cell] element: the slot
// holds a NaN-boxed cell, so the read must unbox before string / int use.
// A body-usage guess of vec[string] would deref the boxed cell raw → SIGSEGV.
function firstLen(array $a): int { return strlen($a[0]); }
function nth(array $a, int $i): string { return $a[$i]; }

$bag = ["hello", 42, 3.14, "world"];
echo firstLen($bag), "\n";
echo nth($bag, 3), "\n";
echo nth($bag, 0), "\n";

// pass-through then reuse original (borrowed, not freed)
echo firstLen($bag) + strlen($bag[3]), "\n";
