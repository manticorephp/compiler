<?php
class P
{
    public function __construct(public float $v) {}
    public function m(float $a, float $b): float { return $a + $b; }
    public static function s(float $a, float $b): float { return $a + $b; }
    public function __invoke(float $a): float { return $a * 2; }
}
function f(float $a, float $b): float { return $a + $b; }

$i = 3;
$p = new P(2);
var_dump($p->v);
var_dump((new P($i))->v);
var_dump($p->m(0, 1));
var_dump($p->m($i, true));
var_dump(P::s(0, 1));
var_dump(P::s($i, 1));
$n = $p;
var_dump($n?->m(1, 2));
var_dump(call_user_func([$p, 'm'], 1, 2));
var_dump(call_user_func('P::s', 1, 2));
var_dump(call_user_func_array([$p, 'm'], [1, 2]));
$fc = $p->m(...);
var_dump($fc(1, 2));
$sc = P::s(...);
var_dump($sc(1, 2));
$ff = f(...);
var_dump($ff(1, 2));
$name = 'm';
var_dump($p->$name(1, 2));
$cn = 'P';
var_dump($cn::s(1, 2));
var_dump((new P(1))->m(...)(4, 5));
var_dump($p(4));
$cl = fn(float $x): float => $x / 2;
var_dump($cl(3));
