<?php
interface I { #[\NoDiscard] public function m(): int; }
abstract class A implements I { #[\NoDiscard] abstract public function n(): int; }
class Impl extends A {
  public function m(): int { return 1; }
  public function n(): int { return 2; }
  #[\NoDiscard] public function own(): int { return 3; }
}
echo "start\n";
$i = new Impl();
$i->m();
$i->n();
$i->own();
echo "end\n";
