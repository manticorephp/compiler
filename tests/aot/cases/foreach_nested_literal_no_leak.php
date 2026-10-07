<?php

// A fresh foreach literal whose elements are arrays owns one reference on each
// of them; the loop must give those back with the buffer, on every way out.
// A borrowed element (a local) and the last value must survive the loop.
// @serial: a memory measurement.
function plain(int $n): int { $t = 0;
  for ($i = 0; $i < $n; $i++) { foreach ([[$i, 2], [3, $i]] as $v) { $t += $v[0]; } }
  return $t; }
function strs(int $n): int { $t = 0;
  for ($i = 0; $i < $n; $i++) { foreach ([['a' . $i, 'b'], ['c', 'd' . $i]] as $v) { $t += strlen($v[0]); } }
  return $t; }
function exits(int $n): int { $t = 0;
  for ($i = 0; $i < $n; $i++) {
    foreach ([[$i, 1], [2, $i], [$i, $i]] as $v) {
      if ($i % 3 === 0) { break; }
      if ($i % 3 === 1) { continue 2; }
      $t += $v[1];
    }
  }
  return $t; }
function ret(int $i): int { foreach ([[$i, 5], [6, $i]] as $v) { if ($v[1] === 5) { return $v[0]; } } return -1; }
function keep(int $n): string { $x = ['k' . $n, 'x']; $last = [];
  for ($i = 0; $i < $n; $i++) { foreach ([$x, ['y' . $i]] as $v) { $last = $v; } }
  return $x[0] . '|' . $last[0] . '|' . $v[0]; }
function assoc(int $n): int { $t = 0;
  for ($i = 0; $i < $n; $i++) { foreach (['a' => [$i], 'b' => [$i, $i]] as $v) { $t += count($v); } }
  return $t; }
$n = 200000; $m0 = memory_get_usage(); $r = 0;
$r += plain($n); $r += strs($n); $r += exits($n); $r += assoc($n);
for ($i = 0; $i < $n; $i++) { $r += ret($i); }
echo $r, " ", keep($n), "\n";
$d = memory_get_peak_usage() - $m0; echo $d < 20000000 ? "ok" : "LEAK " . $d, "\n";
