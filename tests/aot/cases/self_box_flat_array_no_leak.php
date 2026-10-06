<?php

// A merge box-back `$x = box($x)` of a flat array MOVES the slot's one count into
// the cell (OwnershipFlow SELF_MOVE); the shallow box retained it on a plain
// (non-mixed) slot and every call leaked the array. @serial: a memory measurement.
function f(int $n): int { $x = [1, 2, 3 + $n, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20]; if ($n < 0) { $x = "s" . $n; } return is_array($x) ? count($x) : 0; }
$m0 = memory_get_usage(); $t = 0; for ($i = 0; $i < 200000; $i++) { $t += f($i); }
echo $t, " ", (memory_get_usage() - $m0) < 3000000 ? "ok" : "LEAK " . (memory_get_usage() - $m0), "\n";
