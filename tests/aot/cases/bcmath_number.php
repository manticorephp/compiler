<?php
// BcMath\Number: scales, errors, immutability, compare, round, floor/ceil, sqrt, pow/powmod, serialize.
use BcMath\Number;

function t(callable $f): void
{
    try {
        $r = $f();
        echo $r instanceof Number ? "N({$r->value},{$r->scale})" : var_export($r, true);
    } catch (\Throwable $e) {
        echo get_class($e), ': ', $e->getMessage();
    }
    echo "\n";
}

foreach (['1.50', '-0.00', '+3', '007.10', '.5', '1.', '', '-.5', 'abc', '1e5', ' 1', '1.2.3', '-00.10'] as $s) {
    echo json_encode($s), ' ';
    t(fn() => new Number($s));
}
t(fn() => new Number(5));
t(fn() => new Number(PHP_INT_MIN));

$a = new Number('1.5');
t(fn() => $a->add('2.25'));
t(fn() => $a->add('2.25', 5));
t(fn() => $a->add('2.25', 0));
t(fn() => $a->add('2.25', -1));
t(fn() => $a->add(new Number('2.255'), 1));
t(fn() => $a->sub('2.5'));
t(fn() => $a->sub(new Number('-1.25')));
t(fn() => $a->mul('2.25'));
t(fn() => $a->mul('2.25', 1));
t(fn() => $a->mul('2.25', 9));
t(fn() => (new Number('1'))->div('3'));
t(fn() => $a->div('3'));
t(fn() => (new Number('1.50'))->div('3'));
t(fn() => $a->div('3.25'));
t(fn() => (new Number('1'))->div('3', 2));
t(fn() => (new Number('10'))->div('4'));
t(fn() => $a->div('0'));
t(fn() => $a->mod('0'));
t(fn() => (new Number('7.5'))->mod('2'));
t(fn() => (new Number('7.50'))->mod('2.500'));
t(fn() => (new Number('-7'))->mod('2'));
t(fn() => (new Number('7.5'))->mod('2', 0));
t(fn() => (new Number('2'))->pow(3));
t(fn() => (new Number('2.5'))->pow(2));
t(fn() => (new Number('3'))->pow(-2));
t(fn() => (new Number('3.5'))->pow(3, 1));
t(fn() => (new Number('2.5'))->pow(-2));
t(fn() => (new Number('2'))->pow('1.5'));
t(fn() => (new Number('2'))->pow(0));
t(fn() => (new Number('0'))->pow(-1));
t(fn() => (new Number('2'))->pow('9999999999999999999'));
t(fn() => (new Number('4'))->powmod(3, 5));
t(fn() => (new Number('4'))->powmod(3, 5, 2));
t(fn() => (new Number('4.5'))->powmod(3, 5));
t(fn() => (new Number('4'))->powmod(-3, 5));
t(fn() => (new Number('4'))->powmod(3, 0));
t(fn() => (new Number('4'))->powmod(3, '5.5'));
t(fn() => (new Number('4'))->powmod('3', 'abc'));
t(fn() => (new Number('2'))->sqrt());
t(fn() => (new Number('2.50'))->sqrt());
t(fn() => (new Number('2.5'))->sqrt(8));
t(fn() => (new Number('16'))->sqrt());
t(fn() => (new Number('-1'))->sqrt());
t(fn() => (new Number('0.000'))->sqrt());
t(fn() => (new Number('2'))->sqrt(-1));
t(fn() => (new Number('2.5'))->floor());
t(fn() => (new Number('-2.5'))->floor());
t(fn() => (new Number('-2.5'))->ceil());
t(fn() => (new Number('-0.5'))->ceil());
t(fn() => (new Number('2.567'))->round());
t(fn() => (new Number('2.567'))->round(2));
t(fn() => (new Number('1.5'))->round(5));
t(fn() => (new Number('1250'))->round(-2));
t(fn() => (new Number('1.55'))->round(1));
t(fn() => (new Number('-1.55'))->round(1));
t(fn() => $a->compare('1.50'));
t(fn() => $a->compare('1.51', 1));
t(fn() => $a->compare('1.51'));
t(fn() => $a->compare(new Number('1.4')));
t(fn() => $a->compare('x'));
t(fn() => $a->add('abc'));
t(fn() => (string) new Number('1.50'));
t(fn() => $a->value . '/' . $a->scale);

$s = serialize(new Number('1.50'));
echo $s, "\n";
$u = unserialize($s);
echo get_class($u), ' ', $u->value, ' ', $u->scale, "\n";
t(fn() => unserialize('O:13:"BcMath\Number":2:{s:5:"value";s:3:"1.5";s:5:"scale";i:3;}'));
t(fn() => unserialize('O:13:"BcMath\Number":1:{s:5:"value";s:3:"abc";}'));

t(function () { $n = new Number('1'); $n->value = '2'; });
t(function () { $n = new Number('1'); $n->scale = 2; });
t(fn() => clone $a);
t(fn() => $a == new Number('1.5'));
t(fn() => $a instanceof Stringable);
var_dump($a);
echo json_encode($a), "\n";
