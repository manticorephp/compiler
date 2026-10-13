<?php
// Calling an ANCESTOR's method as `Ancestor::method()` from an instance method forwards $this (skipping the direct parent's override).
abstract class A {
    public function __construct(protected int $f = 0) { echo "A\n"; }
    public function hi(): string { return 'A::hi ' . $this->f; }
}
class B extends A {
    public function __construct($x = 0) { parent::__construct($x); echo "B\n"; }
    public function hi(): string { return 'B::hi'; }
}
class C extends B {
    public function __construct() { A::__construct(5); echo "C ", $this->f, "\n"; }
    public function hi(): string { return A::hi() . ' / ' . B::hi(); }
}
$c = new C();
echo $c->hi(), "\n";
