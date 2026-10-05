<?php

// A foreach over an array LITERAL owns that fresh array: it is released when the
// loop ends, and on a break / continue N / return out of it. @serial: a memory
// measurement.
function plain(int $n, string $p): int { $t = 0;
  for ($i = 0; $i < $n; $i++) { foreach ([$p . str_repeat('c', 300), $p . 'd'] as $v) { $t += strlen($v); } }
  return $t; }
function brk(int $n, string $p): int { $t = 0;
  for ($i = 0; $i < $n; $i++) { foreach ([$p . str_repeat('c', 300), $p . 'd'] as $v) { $t += strlen($v); break; } }
  return $t; }
function cont2(int $n, string $p): int { $t = 0;
  for ($i = 0; $i < $n; $i++) { foreach ([$p . str_repeat('c', 300), $p . 'd'] as $v) { $t += strlen($v); continue 2; } }
  return $t; }
function ret(string $p): int { foreach ([$p . str_repeat('c', 300), $p . 'd'] as $v) { return strlen($v); } return 0; }
$m0 = memory_get_usage(); $t = plain(100000, 'q') + brk(100000, 'q') + cont2(100000, 'q');
for ($i = 0; $i < 100000; $i++) { $t += ret('q'); }
echo $t, " ", (memory_get_peak_usage() - $m0) < 20000000 ? "ok" : "LEAK " . (memory_get_peak_usage() - $m0), "\n";
