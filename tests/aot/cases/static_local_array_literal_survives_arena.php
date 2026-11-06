<?php
function table(int $i): int
{
    static $t = [10, 20, 30, 40, 50, 60, 70, 80, 90];
    return $t[$i];
}
function churn(int $r): int
{
    $x = str_repeat('7', 64) . (string)$r;
    $y = $x . $x . 'zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz';
    return strlen($y);
}
echo table(0), "\n";
$sum = 0;
for ($r = 0; $r < 50; $r++) { $sum += churn($r); }
echo $sum, "\n";
for ($i = 0; $i < 9; $i++) { echo table($i), " "; }
echo "\n";
