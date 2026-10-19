<?php
// A by-ref arg to an interface method whose FIRST declarer does not keep a reference, but another does.
interface I { function bind(mixed &$v): void; }
class A implements I { function bind(mixed &$v): void {} }
class B implements I { public array $r = []; function bind(mixed &$v): void { $this->r[] = &$v; } }
abstract class Base { abstract function keep(mixed &$v): void; }
class Keeper extends Base { public array $r = []; function keep(mixed &$v): void { $this->r[] = &$v; } }
class Child extends A { public array $r = []; function bind(mixed &$v): void { $this->r[] = &$v; } }
function viaIface(I $o): void { $i = 41; $o->bind($i); echo $i + 1, "\n"; $i = 50; }
function viaIfaceS(I $o): void { $s = "str"; $o->bind($s); echo $s, "\n"; $s = "later"; }
function viaIfaceM(I $o, int $n): void { $m = $n > 0 ? "x" : 1.5; $o->bind($m); var_dump($m); $m = [$n]; }
function viaAbstract(Base $o): void { $f = 2.5; $o->keep($f); echo $f, "\n"; $f = 3.5; }
function viaParent(A $o): void { $q = "p"; $o->bind($q); echo $q, "\n"; $q = "q"; }
$b = new B;
viaIface(new A); viaIface($b); viaIfaceS($b); viaIfaceM($b, 1); viaIfaceM($b, 0);
var_dump($b->r);
$b->r[0] = 7; echo $b->r[0], "\n";
$k = new Keeper; viaAbstract($k); var_dump($k->r);
$c = new Child; viaParent($c); viaParent(new A); var_dump($c->r);
