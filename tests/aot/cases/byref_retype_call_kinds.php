<?php
// A typed by-ref param overwritten with another kind makes the CALLER's slot a
// cell for every call kind — a method, a static method, a nullsafe call and a
// constructor reach the callee through the same shared word as a free call.
// Only the free call was covered; the others kept the raw caller slot.
final class C {
    public function __construct(?string &$c) { $c = 1; }
    public function m(string &$c): void { $c = 7; }
    public static function s(string &$c): void { $c = 2.5; }
    public function app(string &$x): void { $x .= 'a'; }
}
$o = new C($k0);
var_dump($k0);
$a = 'a'; $o->m($a); var_dump($a);
$b = 'b'; C::s($b); var_dump($b);
$n = rand(0, 1) > 5 ? null : $o;
$c = 'c'; $n?->m($c); var_dump($c);
$k = 'k'; $o2 = new C($k); var_dump($k);
$i = 12; $o->app($i); var_dump($i);
