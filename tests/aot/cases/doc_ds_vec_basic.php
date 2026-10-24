<?php

use Manticore\Ds\Vec;

$v = new Vec();
$v->push('a');
$v[] = 'b';
$v->push(42);                   // any type
echo count($v), "\n";           // 3
echo $v[1], "\n";               // b

$v[0] = 'z';
echo $v->pop(), "\n";           // 42

$nums = Vec::fromArray([1, 2, 3, 4]);
$squares = $nums->map(fn($n) => $n * $n);
echo json_encode($squares), "\n";
echo $nums->filter(fn($n) => $n % 2 === 0)->reduce(fn($sum, $n) => $sum + $n, 0), "\n";

try {
    echo $v[10];
} catch (OutOfBoundsException $e) {
    echo $e->getMessage(), "\n";
}
