<?php
function calc(int $a, int $b): int {
    $sum = $a + $b;
    $diff = $a - $b;
    $prod = $a * $b;
    $quot = $a / $b;
    $rem = $a % $b;
    $neg = -$a;
    return $sum + $diff + $prod + $quot + $rem + $neg;
}
echo calc(10, 3);
