<?php
// Regression: a nested float array that receives a nested element WRITE
// (`$b[i][j] = v`) must NOT be demoted to vec[cell] — it stays vec[vec[float]],
// so (1) cell*cell arithmetic on its elements stays float math (not integer
// `mul` on float bit-patterns) and (2) it can be passed to a bare-`array` param
// whose body reads elements raw without faulting on a boxed cell. This is the
// n-body kernel shape. Before the fix: garbage floats then SIGSEGV at the call.

function energy(array $b, int $n): float {
    $e = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $e += $b[$i][2] * ($b[$i][0] * $b[$i][0] + $b[$i][1] * $b[$i][1]);
    }
    return $e;
}

$b = [
    [1.0, 2.0, 0.5],
    [3.0, 4.0, 0.25],
    [5.0, 6.0, 0.125],
];
$n = count($b);

// nested element write-back — the demotion trigger
$px = 0.0;
for ($i = 0; $i < $n; $i++) { $px += $b[$i][0] * $b[$i][2]; }
$b[0][0] = -$px;

printf("%.6f\n", $px);
printf("%.6f\n", $b[0][0]);
printf("%.6f\n", energy($b, $n));
