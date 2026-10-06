<?php

// A callee that allocated in its arena frame throws into a catch inside a
// generator; every throw must be reclaimed at the generator's landing, and a
// generator left suspended must leave nothing behind either.
// @serial: a memory measurement.
function h(string $p, int $j): int {
  $s = $p . str_repeat('c', 1000);
  if ($j === 1) { throw new \RuntimeException($s[0]); }
  return strlen($s); }
function gen(string $p, int $n) {
  for ($i = 0; $i < $n; $i++) {
    try { h($p, 0); h($p, 1); } catch (\RuntimeException $e) { yield $i; }
  }
}
function drive(string $p, int $n): int { $t = 0; foreach (gen($p, $n) as $v) { $t += $v; } return $t; }
function abandon(string $p, int $n): int { $t = 0;
  for ($i = 0; $i < $n; $i++) { $g = gen($p, 5); $t += $g->current(); $g->next(); $t += $g->current(); }
  return $t; }
$m0 = memory_get_usage();
echo drive('q', 300000), " ", abandon('q', 100000), "\n";
$d = memory_get_peak_usage() - $m0;
echo $d < 60000000 ? "ok" : "LEAK " . $d, "\n";
