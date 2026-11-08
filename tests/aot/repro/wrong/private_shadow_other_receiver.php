<?php
// A parent's method reaching a redeclared private through a receiver other than $this gets the subclass's slot.
// issue: #577
class P { private string $r = 'parent';
  public function viaParam(C $o): string { return $o->r; }
  public function viaErased($o): string { return $o->r; }
  public function viaNarrow(): string { return $this instanceof C ? $this->r : '?'; }
  public function viaBound(): string { return (function () { return $this->r; })->call($this); } }
class C extends P { private string $r = 'child'; }
$c = new C;
echo $c->viaParam($c), ' ', $c->viaErased($c), ' ', $c->viaNarrow(), ' ', $c->viaBound(), "\n";
$f = Closure::bind(function () { return $this->r; }, $c, P::class);
echo $f(), "\n";
