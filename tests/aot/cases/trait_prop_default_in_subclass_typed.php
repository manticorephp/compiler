<?php
trait T { private string $ver = 'v'; protected array $items = ['a']; private ?int $max = null; public function g() { return $this->ver . count($this->items); } }
class A { use T; }
class B extends A { }
class C extends B { public function __construct(private bool $x = false) {} }
echo (new A)->g(), '|', (new B)->g(), '|', (new C)->g(), "\n";
trait U { public int $n = 5; }
class P { use U; }
class Q extends P { public int $n = 9; }
echo (new P)->n, (new Q)->n, "\n";
