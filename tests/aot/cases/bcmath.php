<?php
function t(callable $f): void
{
    try {
        echo json_encode($f()), "\n";
    } catch (Throwable $e) {
        echo get_class($e), ': ', $e->getMessage(), "\n";
    }
}

foreach (['', '-', '.', '1.', '.5', '+1', '-0', '00012.50', ' 1', '1e3', '1.5.2'] as $s) {
    t(fn() => bcadd($s, '0', 2));
}
t(fn() => bcadd('123456789012345678901234567890', '987654321098765432109876543210'));
t(fn() => bcadd('-1.005', '1', 3));
t(fn() => bcsub('1', '2.5', 2));
t(fn() => bcsub('0.1', '0.1', 4));
t(fn() => bcmul('-123456789.123456789', '987654321.987654321', 10));
t(fn() => bcmul('0.0001', '-0.0001', 2));
t(fn() => bcdiv('1', '3', 20));
t(fn() => bcdiv('-10', '4', 1));
t(fn() => bcdiv('1', '0'));
t(fn() => bcmod('-7.5', '2', 1));
t(fn() => bcmod('10', '3'));
t(fn() => bcmod('1', '0'));
t(fn() => bcdivmod('-7.5', '2', 1));
t(fn() => bcdivmod('7', '0'));
t(fn() => bccomp('1.001', '1.0009', 3));
t(fn() => bccomp('1.001', '1.0009', 4));
t(fn() => bccomp('-0.0001', '0', 2));
t(fn() => bccomp('-5', '3'));
t(fn() => bcpow('2', '100'));
t(fn() => bcpow('1.5', '3', 0));
t(fn() => bcpow('2', '-2', 3));
t(fn() => bcpow('-3', '5'));
t(fn() => bcpow('0', '-1'));
t(fn() => bcpow('2', '1.5'));
t(fn() => bcpow('2', '99999999999999999999'));
t(fn() => bcsqrt('2', 30));
t(fn() => bcsqrt('0.0000001', 12));
t(fn() => bcsqrt('-1'));
t(fn() => bcpowmod('4', '13', '497'));
t(fn() => bcpowmod('-7', '3', '10'));
t(fn() => bcpowmod('2', '-1', '5'));
t(fn() => bcpowmod('2', '3', '0'));
t(fn() => bcpowmod('2.5', '3', '7'));
t(fn() => bcfloor('-1.5'));
t(fn() => bcfloor('1.999'));
t(fn() => bcceil('-0.5'));
t(fn() => bcceil('1.001'));
t(fn() => bcround('3.14159', 2));
t(fn() => bcround('1.5', 3));
t(fn() => bcround('1.55', 1));
t(fn() => bcround('-1.55', 1));
t(fn() => bcround('1234.5', -2));
t(fn() => bcround('0.999', 2));
var_dump(bcscale(), bcscale(3), bcscale());
echo bcadd('1', '2'), "\n", bcdiv('1', '3'), "\n";
t(fn() => bcscale(-1));
t(fn() => bcadd('1', '1', -1));
