<?php
// Ternary / match arms holding objects of DIFFERENT classes are typed from the first arm
// issue: #64
final class A { public function n(): string { return 'A'; } }
final class B { public function n(): string { return 'B'; } }
function p(bool $c): string { $o = $c ? new A() : new B(); return $o->n(); }
function q(int $c): string { $o = match ($c) { 0 => new A(), default => new B() }; return $o->n(); }
echo p(true), p(false), q(0), q(1), "\n";
