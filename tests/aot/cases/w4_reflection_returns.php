<?php
class K {
    const BIG = 70000;
    const S = "str";
    public int $p = 70000;
    public mixed $q = "q";
    public function m(int $x): int { return $x + 70000; }
    public function d(int $x = 65536): int { return $x; }
}
$rc = new ReflectionClass(K::class);
var_dump($rc->getConstant('BIG'), $rc->getConstant('S'));
$k = new K();
$rp = new ReflectionProperty(K::class, 'p');
var_dump($rp->getValue($k));
$rq = new ReflectionProperty(K::class, 'q');
var_dump($rq->getValue($k));
$rm = new ReflectionMethod(K::class, 'm');
var_dump($rm->invoke($k, 1), $rm->invokeArgs($k, [2]));
function ulen(string $s): int { return strlen($s) + 70000; }
$rf = new ReflectionFunction('ulen');
var_dump($rf->invoke('abc'));
$rd = new ReflectionMethod(K::class, 'd');
var_dump($rd->getParameters()[0]->getDefaultValue());
var_dump(class_parents($k), class_implements($k));
