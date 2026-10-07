<?php

// An outer loop whose arena-bound local an inner loop also rebinds: the inner
// loop cannot reset, its stores are demoted, and the outer loop must then either
// reset or hold no arena allocation — its temporaries must not pile up until the
// frame returns. @serial: a memory measurement.
function g(int $n, string $p): int { $t = 0; $i = 0;
  while ($i < $n) { $s = $p . (string)$i . str_repeat('a', 200); $j = 0;
    while ($j < 2) { $s = $p . (string)$j . 'bbbbbbbbbbbbbbb'; $j++; }
    $t += strlen($s); $i++; }
  return $t; }
$m0 = memory_get_usage(); $r = g(300000, 'q');
echo $r, " ", (memory_get_peak_usage() - $m0) < 20000000 ? "ok" : "LEAK " . (memory_get_peak_usage() - $m0), "\n";
