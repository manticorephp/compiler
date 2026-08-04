<?php
// closures — 2 M closure build + call (capture by value, immediate invoke).
// (* $argc) so `LEAK=1 bench/run.sh` can scale the iteration count.
$n = 2000000 * $argc;
$sum = 0;
for ($i = 0; $i < $n; $i++) {
    $add = fn(int $x): int => $x + $i;
    $sum += $add($i);
}
echo $sum, "\n";
