<?php
// bcmath — money-sized operands (the invoice/tax pattern): add, mul, div, comp, round.
// Operands vary with the accumulator so nothing is hoisted.
$acc = '0.00';
$n = 60000 * $argc;
for ($i = 0; $i < $n; $i++) {
    $p = (string) (($i % 9973) + 1) . '.' . str_pad((string) ($i % 100), 2, '0', STR_PAD_LEFT);
    $t = bcmul($p, '1.2075', 4);
    $t = bcround($t, 2);
    $acc = bcadd($acc, $t, 2);
    if (bccomp($acc, '1000000000.00', 2) > 0) {
        $acc = bcdiv($acc, '3', 2);
    }
    $acc = bcsub($acc, bcmod($p, '7', 2), 2);
}
echo $acc, "\n";
