<?php

class A {}
class B extends A {}
class C {}

function kind(object $o): string
{
    if (!$o instanceof A) { return 'not-A'; }
    return !$o instanceof B ? 'A-only' : 'B';
}
echo kind(new A()), ' ', kind(new B()), ' ', kind(new C()), "\n";

$o = new C();
var_dump(!$o instanceof A, !($o instanceof A), (!$o) instanceof A);
var_dump(!$o instanceof C && true);

$x = 2;
var_dump(-2 ** 2, -$x ** 2, 2 ** -1, 2 ** 3 ** 2, (-2) ** 2);
var_dump(!$x * 3, ~$x ** 2, (int)'3' ** 2);
$n = -1;
$m = 3;
var_dump($x ** $n, $x ** $m, $x ** ($n + 4), 3 ** $m * 2);
