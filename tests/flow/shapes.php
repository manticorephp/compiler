<?php
// ownflow-args: f,f2
function f(int $n): int {
    if ($n > 0) { $a = 1; } else { $b = 2; }
    while ($n-- > 0) { $c = 3; if ($n === 5) { break; } $d = 4; }
    foreach ([1, 2] as $k => $v) { $e = $v; continue; }
    switch ($n) { case 1: $g = 1; break; default: $h = 2; }
    try { $t = 1; } catch (\Exception $x) { $u = 2; } finally { $w = 3; }
    L: $z = 1;
    return $n;
}
echo f(3), "\n";
function f2(int $n): int {
    if ($n > 0) { $a = 1; } else { $b = 2; }
    while ($n-- > 0) { $c = 3; if ($n === 5) { break; } $d = 4; }
    foreach ([1, 2] as $k => $v) { $e = $v; continue; }
    switch ($n) { case 1: $g = 1; break; default: $h = 2; }
    try { $t = 1; } catch (\Exception $x) { $u = 2; } finally { $w = 3; }
    L: $z = 1;
    echo isset($a, $b, $c, $d, $e, $g, $h, $k, $v, $t, $u, $w, $x, $z) ? 1 : 0;
    return $n;
}
echo f2(3), "\n";
