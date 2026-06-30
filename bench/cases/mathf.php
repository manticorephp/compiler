<?php
// mathf — 3 M sqrt + sin accumulate (libm intrinsic throughput).
$acc = 0.0;
for ($i = 1; $i <= 3000000; $i++) {
    $acc += sqrt((float)$i) + sin((float)$i);
}
printf("%.6f\n", $acc);
