<?php

// An inner `for` INIT runs before the inner loop's arena save, so it belongs to
// the outer loop, and an erased-base outer loop never resets. @serial: a memory
// measurement.
function g(mixed $items, string $p): int { $t = 0;
  foreach ($items as $x) {
    for ($j = strlen($p . str_repeat("c", 300)); $j < 303; $j++) { $t += strlen($p . (string)$j) + $x; }
  }
  return $t; }
$m0 = memory_get_usage(); $r = g(array_fill(0, 300000, 1), 'q');
echo $r, " ", (memory_get_peak_usage() - $m0) < 20000000 ? "ok" : "LEAK " . (memory_get_peak_usage() - $m0), "\n";
