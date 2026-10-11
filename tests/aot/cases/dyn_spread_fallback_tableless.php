<?php
// The shared spread fallback serves objects without a method table (prelude
// classes) and must not dispatch over every user class.
class Cat { public function count(int $n = 1, string $s = 'c'): string { return "cat$n$s"; } }
class Dog { public function count(int $n = 2): string { return "dog$n"; } public function add(string $k, $v = 0): string { return "dog+$k$v"; } }
function mk(int $i): object { return match ($i) { 0 => new Cat, 1 => new Dog, 2 => new ArrayObject([1, 2, 3]), default => new ArrayIterator([4, 5]) }; }
$m = 'count';
$none = [];
$one = [7];
for ($i = 0; $i < 4; $i++) {
    echo get_class(mk($i)), ' ', mk($i)->$m(...$none), "\n";
}
echo mk(0)->$m(...$one), ' ', mk(1)->$m(...$one), "\n";
$ao = mk(2);
$a = 'append'; $ao->$a(...[10]);
echo count($ao), "\n";
$f = 'add';
echo mk(1)->$f(...['k', 5]), "\n";
