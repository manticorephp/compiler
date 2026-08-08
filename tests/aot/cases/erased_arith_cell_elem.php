<?php
// Arithmetic whose operand is a CELL array element, stored back into that
// same cell-element array. `array_fill` returns `mixed[]` (a vec[cell]), so
// `$a[$i]` reads a cell, the integer path unboxes it to a raw i64 — and the
// result was typed UNKNOWN, which sent the store through the probing boxer.
// That probe dereferences the word at ptr-8 to read an allocator magic, so
// every value above 65535 faulted. Both sides of that threshold are here.
$a = array_fill(0, 3, 0);
$a[0] = $a[0] + 7;
$a[1] = $a[1] + 70000;
var_dump($a[0], $a[1]);

$b = array_fill(0, 2, 0);
$b[0] += 70000;
$b[1] -= 70000;
var_dump($b[0], $b[1]);

$c = array_fill(0, 2, 2);
$c[0] = $c[0] * 70000;
var_dump($c[0]);

// The erased sum feeding a CONCAT is the same boundary one consumer over.
$d = array_fill(0, 1, 0);
echo "n" . ($d[0] + 70000), "\n";

// A computed index, so the store cannot be folded to a constant slot.
$e = array_fill(0, 4, 0);
$i = 2;
$e[$i] = $e[$i] + 353307;
var_dump($e[2]);
