<?php

// A throw that leaves a resetting inner loop, or a callee's arena frame, lands
// in a catch inside an outer loop that never resets (an erased base). The
// landing must reclaim what the unwound iteration and frames left behind, or it
// piles up once per throw. @serial: a memory measurement.
function h(string $p, int $j): int {
  $s = $p . str_repeat('c', 1000);
  if ($j === 1) { throw new \RuntimeException($s[0]); }
  return strlen($s); }
function inLoop(mixed $items, string $p): int { $t = 0;
  foreach ($items as $x) {
    try {
      for ($j = 0; $j < 3; $j++) {
        $s = $p . str_repeat('c', 1000);
        if ($j === 1) { throw new \RuntimeException('x'); }
        $t += strlen($s);
      }
    } catch (\RuntimeException $e) { $t += $x; }
  }
  return $t; }
function inCallee(mixed $items, string $p): int { $t = 0;
  foreach ($items as $x) {
    try { $t += h($p, 0); $t += h($p, 1); } catch (\RuntimeException $e) { $t += $x; }
  }
  return $t; }
function viaFinally(mixed $items, string $p): int { $t = 0;
  foreach ($items as $x) {
    try {
      try {
        for ($j = 0; $j < 3; $j++) {
          $s = $p . str_repeat('c', 1000);
          if ($j === 1) { throw new \RuntimeException('x'); }
          $t += strlen($s);
        }
      } finally { $t += 2; }
    } catch (\RuntimeException $e) { $t += $x; }
  }
  return $t; }
$items = array_fill(0, 100000, 1);
$m0 = memory_get_usage();
echo inLoop($items, 'q'), " ", inCallee($items, 'q'), " ", viaFinally($items, 'q'), "\n";
$d = memory_get_peak_usage() - $m0;
echo $d < 60000000 ? "ok" : "LEAK " . $d, "\n";
