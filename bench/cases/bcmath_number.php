<?php
// BcMath\Number — the object API over the same money-sized work as bcmath_money.
use BcMath\Number;

$acc = new Number('0.00');
$rate = new Number('1.2075');
$limit = new Number('1000000000.00');
$n = 60000 * $argc;
for ($i = 0; $i < $n; $i++) {
    $p = new Number((string) (($i % 9973) + 1) . '.' . str_pad((string) ($i % 100), 2, '0', STR_PAD_LEFT));
    $t = $p->mul($rate, 4)->round(2);
    $acc = $acc->add($t, 2);
    if ($acc->compare($limit, 2) > 0) {
        $acc = $acc->div('3', 2);
    }
    $acc = $acc->sub($p->mod('7', 2), 2);
}
echo $acc, "\n";
