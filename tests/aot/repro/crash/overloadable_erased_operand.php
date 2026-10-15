<?php
// Operator overloading is skipped when the operand is an erased (mixed/cell) value, so BcMath\Number arithmetic computes on the pointer.
// issue: #263
use BcMath\Number;
function plus(mixed $x, mixed $y): mixed { return $x + $y; }
function lt(mixed $x, mixed $y): bool { return $x < $y; }
echo plus(new Number('1.5'), new Number('2')), "\n";
echo plus(new Number('1.5'), 1), "\n";
var_dump(lt(new Number('1'), new Number('2')));
$items = [new Number('1'), new Number('2')];
echo array_reduce($items, fn($c, $n) => $c + $n, new Number('0')), "\n";
