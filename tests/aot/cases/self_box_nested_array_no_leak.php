<?php

// A merge box-back `$x = box($x)` of an array whose elements have no hint (enum,
// nested array, closure) rebuilds the array into the cell: the rebuilt copy
// replaces the slot's count and the old buffer is released. @serial: a memory measurement.
enum Suit { case H; case S; }
function e(int $n): int { $x = [Suit::H, Suit::S, Suit::H]; if ($n < 0) { $x = "s" . $n; } return is_array($x) ? count($x) : 0; }
function g(int $n): int { $x = [[1, 2], [3, $n]]; if ($n < 0) { $x = "s" . $n; } return is_array($x) ? count($x) : 0; }
function c(int $n): int { $x = [fn() => 1, fn() => $n]; if ($n < 0) { $x = "s" . $n; } return is_array($x) ? count($x) : 0; }
$m0 = memory_get_usage(); $t = 0;
for ($i = 0; $i < 200000; $i++) { $t += e($i) + g($i) + c($i); }
echo $t, " ", (memory_get_usage() - $m0) < 3000000 ? "ok" : "LEAK " . (memory_get_usage() - $m0), "\n";
