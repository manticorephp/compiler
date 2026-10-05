<?php
interface I { public function a(int $x, int $y): int; }
abstract class B { abstract public function z($a, $b); }
class C implements I { public function a(int $x, int $y): int { return $x + $y; } }
class D extends B { public function z($a, $b) { return $a . $b; } }
function viaI(I $i) { $f = $i->a(...); return $f(1, 2); }
function viaB(B $b) { $f = $b->z(...); return $f(1, 2); }
function viaM(mixed $m) { $f = $m->a(...); return $f(4, 5); }
echo viaI(new C), "\n";
echo viaB(new D), "\n";
echo viaM(new C), "\n";
