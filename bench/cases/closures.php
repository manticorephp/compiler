<?php
// closures — 2 M closure build + call (capture by value, immediate invoke).
$sum = 0;
for ($i = 0; $i < 2000000; $i++) {
    $add = fn(int $x): int => $x + $i;
    $sum += $add($i);
}
echo $sum, "\n";
