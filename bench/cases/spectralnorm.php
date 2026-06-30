<?php
// Spectral norm (CLBG) — float, sqrt, nested loops, 1D arrays as vectors.

function A(int $i, int $j): float {
    $n = $i + $j;
    return 1.0 / ($n * ($n + 1) / 2 + $i + 1);
}

/** @param float[] $v @return float[] */
function mulAv(int $n, array $v): array {
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        $sum = 0.0;
        for ($j = 0; $j < $n; $j++) { $sum += A($i, $j) * $v[$j]; }
        $out[$i] = $sum;
    }
    return $out;
}

/** @param float[] $v @return float[] */
function mulAtv(int $n, array $v): array {
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        $sum = 0.0;
        for ($j = 0; $j < $n; $j++) { $sum += A($j, $i) * $v[$j]; }
        $out[$i] = $sum;
    }
    return $out;
}

/** @param float[] $v @return float[] */
function mulAtAv(int $n, array $v): array {
    return mulAtv($n, mulAv($n, $v));
}

$n = 500;
$u = [];
for ($i = 0; $i < $n; $i++) { $u[$i] = 1.0; }
$v = [];
for ($i = 0; $i < 10; $i++) {
    $v = mulAtAv($n, $u);
    $u = mulAtAv($n, $v);
}

$vBv = 0.0; $vv = 0.0;
for ($i = 0; $i < $n; $i++) {
    $vBv += $u[$i] * $v[$i];
    $vv += $v[$i] * $v[$i];
}

printf("%.9f\n", sqrt($vBv / $vv));
