<?php

// A local holding a raw array on one path and null on another (a MIXED slot):
// the null overwrite must release the array. @serial: a memory measurement.
function r1(string $p): int { $a = [$p . 'a', $p . 'b']; if (strlen($p) > 5) { $a = null; } return $a === null ? 0 : count($a); }
function r2(int $n): int { $a = [$n, $n + 1, $n + 2]; if ($n % 2 === 0) { $a = null; } return $a === null ? 1 : count($a); }
$m0 = memory_get_usage(); $t = 0;
for ($i = 0; $i < 200000; $i++) { $t += r1(str_repeat('x', $i % 9)) + r2($i); }
echo $t, " ", (memory_get_usage() - $m0) < 3000000 ? "ok" : "LEAK " . (memory_get_usage() - $m0), "\n";
