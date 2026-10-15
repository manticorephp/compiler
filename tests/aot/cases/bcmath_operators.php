<?php
use BcMath\Number;

$a = new Number('10.5');
$b = new Number('3');
foreach ([
    fn() => $a + $b, fn() => $a - $b, fn() => $a * $b, fn() => $a / $b, fn() => $a % $b,
    fn() => $a ** 2, fn() => 2 ** $b, fn() => 10 - $a, fn() => '1.25' + $a, fn() => $a + 1,
    fn() => -$a, fn() => $a < $b, fn() => $a == new Number('10.50'), fn() => $a == '10.5',
    fn() => $a <=> $b, fn() => 5 < $a, fn() => $a >= 10, fn() => $a != $b, fn() => $a + 'x',
    fn() => 'x' + $a, fn() => $a / 0, fn() => $a + [], fn() => $a === $b,
    fn() => ($a + $b) * 2 - 1, fn() => (new Number('1')) / (new Number('3')),
] as $i => $f) {
    try {
        $r = $f();
        echo $i, ': ', is_object($r) ? $r . ' s' . $r->scale : var_export($r, true), "\n";
    } catch (Throwable $e) {
        echo $i, ': ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
$c = new Number('1.5');
$c += $b;
$c *= 2;
echo $c, "\n";
$i = new Number('0.5');
++$i;
$i++;
--$i;
echo $i, "\n";
for ($k = new Number(0); $k < 3; $k++) {
    echo $k, ' ';
}
echo "\n";
