<?php
// A float element read from a NESTED array passed through an `array`-hinted
// param. The hint erases to unknown; call-site element inference must recover
// vec[vec[float]] so `$b[$i][$j]` is float, not raw bits compiled as int math.
// Covers all three access shapes: chained, intermediate-local, dynamic index.

function chained(array $b): float { return $b[0][1] + $b[1][0]; }
function viaLocal(array $b): float { $row = $b[1]; return $row[0] + $row[2]; }
function dynamic(array $b, int $i): float {
    $s = 0.0;
    for ($j = 0; $j < 3; $j++) { $s += $b[$i][$j]; }
    return $s;
}

$m = [[1.5, 2.5, 3.5], [4.5, 5.5, 6.5]];
printf("%.1f\n", chained($m));
printf("%.1f\n", viaLocal($m));
printf("%.1f\n", dynamic($m, 0));
printf("%.1f\n", dynamic($m, 1));
