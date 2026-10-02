<?php

// An inner loop's condition runs once more than its body: its last evaluation
// outlives the inner loop's reset window. The outer loop (an erased base) never
// resets, so that leftover must be reclaimed by the inner loop's exit, not pile
// up once per outer iteration. @serial: a memory measurement.
function g(mixed $items, string $p): int { $t = 0;
  foreach ($items as $x) {
    for ($j = 0; strlen($p . str_repeat('c', 300)) > $j * 1000; $j++) { $t += $j + $x; }
  }
  return $t; }
$m0 = memory_get_usage(); $r = g(array_fill(0, 300000, 1), 'q');
echo $r, " ", (memory_get_peak_usage() - $m0) < 20000000 ? "ok" : "LEAK " . (memory_get_peak_usage() - $m0), "\n";
