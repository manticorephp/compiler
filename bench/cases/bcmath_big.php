<?php
// bcmath — big operands: 300-digit mul/div/mod, pow, sqrt, powmod (limb loops).
$a = str_repeat('7391846205', 30);
$b = str_repeat('2846', 25) . '.5';
$acc = 0;
$n = 40 * $argc;
for ($i = 0; $i < $n; $i++) {
    $x = bcadd($a, (string) $i);
    $m = bcmul($x, $b, 6);
    $q = bcdiv($m, $b, 10);
    $r = bcmod($x, $b, 3);
    $s = bcsqrt($x, 20);
    $pw = bcpow('1.0001', (string) (50 + $i), 30);
    $pm = bcpowmod((string) ($i + 2), '65537', '1000000007');
    $acc += strlen($m) + strlen($q) + strlen($r) + strlen($s) + strlen($pw) + (int) $pm % 1000;
}
echo $acc, "\n";
