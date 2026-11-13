<?php
#[\Attribute] class A {}
enum Suit: string implements \BackedEnum { case H = 'h'; }
function gen() { yield 1; }
function f(\Throwable $e) { return $e instanceof \Throwable; }
$c = Closure::fromCallable('gen');
$o = new \ArrayObject([]);
$s = new \stdClass;
$w = new \WeakMap();
echo count($o), "\n";
