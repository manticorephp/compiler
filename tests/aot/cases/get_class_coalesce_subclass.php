<?php
class A { public ?A $c = null; function get(): ?A { return $this->c; } }
class B extends A {}
$a = new A;
$c = $a->get();
echo get_class($c ?? new B), "\n";
$a->c = new A;
echo get_class($a->get() ?? new B), "\n";
$b = new B;
$b->c = $b;
echo get_class($b->get() ?? new A), "\n";
echo get_class($c ?? new A), "\n";
