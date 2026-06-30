<?php
// Integer matrix multiply — 2D arrays, tight triple loop, exact int output.

function makeMatrix(int $n, int $seed): array {
    $m = [];
    $v = $seed;
    for ($i = 0; $i < $n; $i++) {
        $row = [];
        for ($j = 0; $j < $n; $j++) {
            $v = ($v * 1103515245 + 12345) % 1000;
            $row[$j] = $v;
        }
        $m[$i] = $row;
    }
    return $m;
}

function matmul(array $a, array $b, int $n): array {
    $c = [];
    for ($i = 0; $i < $n; $i++) {
        $row = [];
        for ($j = 0; $j < $n; $j++) { $row[$j] = 0; }
        for ($k = 0; $k < $n; $k++) {
            $aik = $a[$i][$k];
            for ($j = 0; $j < $n; $j++) {
                $row[$j] += $aik * $b[$k][$j];
            }
        }
        $c[$i] = $row;
    }
    return $c;
}

$n = 120;
$a = makeMatrix($n, 1);
$b = makeMatrix($n, 7);
$c = matmul($a, $b, $n);

$trace = 0;
$sum = 0;
for ($i = 0; $i < $n; $i++) {
    $trace += $c[$i][$i];
    for ($j = 0; $j < $n; $j++) { $sum += $c[$i][$j]; }
}
echo "trace=", $trace, "\n";
echo "sum=", $sum, "\n";
