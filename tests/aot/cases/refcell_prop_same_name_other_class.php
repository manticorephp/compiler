<?php
// A reference stored into one class's property must not change how a same-named property of another class behaves, and must still work through every path that reaches the referenced slot.
final class A { private int $x = 1; public function refs(): array { return [&$this->x]; } public function get(): int { return $this->x; } }
final class B { public int $x = 1; }
final class C { private int $x = 3; public function get(): int { return $this->x; } public function set(int $v): void { $this->x = $v; } }
final class D { private mixed $__s = 'd'; public function get(): mixed { return $this->__s; } }

$a = new A();
$ra = $a->refs();
$ra[0] = 5;
echo $a->get(), "\n";

$b = new B();
$rb = [&$b->x];
$rb[0] = 7;
echo $b->x, "\n";

$c = new C();
echo $c->get(), "\n";
$c->set(4);
echo $c->get(), "\n";
$rc = Closure::bind(function (): array { return [&$this->x]; }, $c, C::class)();
$rc[0] = 9;
echo $c->get(), "\n";
$c->set(10);
echo $rc[0], "\n";

$u = new class { public mixed $__s = 'u'; };
$ru = [&$u->__s];
$ru[0] = 'changed';
echo $u->__s, ' ', (new D())->get(), "\n";

$ao = new ArrayObject([3, 1, 2]);
$ao[] = 4;
echo count($ao), ' ', implode(',', $ao->getArrayCopy()), "\n";
