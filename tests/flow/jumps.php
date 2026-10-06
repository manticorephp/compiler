<?php
// ownflow-args: g
function g(int $n): int {
    $i = 0;
    back:
    $i++;
    if ($i < 3) { $p = 1; goto back; }
    for ($j = 0; $j < $n; $j++) {
        foreach ([1] as $v) { $q = 1; if ($v) { break 2; } continue 2; }
    }
    do { $r = 1; if ($n) { continue; } $s = 1; } while ($n-- > 0);
    try {
        while (true) { $m = 1; if ($n) { break; } try { return $n; } finally { $fin = 1; } }
    } finally { $w = 1; }
    $y = match ($n) { 1, 2 => $o = 1, default => throw new \Exception('x') };
    echo isset($i, $p, $j, $v, $q, $r, $s, $m, $fin, $w, $y, $o) ? 1 : 0;
    return $n;
}
try { echo g(1), "\n"; } catch (\Exception $e) { echo "caught\n"; }
