<?php
class A { private int $p = 7; private function m(): int { return 3; } }
$m = new ReflectionMethod(A::class, 'm');
$m->setAccessible(true);
echo $m->invoke(new A), "\n";
$p = new ReflectionProperty(A::class, 'p');
$p->setAccessible(true);
echo $p->getValue(new A), "\n";
