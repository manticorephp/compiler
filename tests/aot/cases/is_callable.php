<?php
function myfn(int $x): int { return $x; }
class C {
    public function m(): int { return 1; }
    public static function s(): int { return 2; }
    public function __invoke(): int { return 3; }
}
class D {}
$cl = fn() => 42;
var_dump(is_callable($cl));            // closure → true
var_dump(is_callable('myfn'));         // user fn → true
var_dump(is_callable('nope'));         // missing → false
var_dump(is_callable('C::s'));         // static method string → true
$o = new C();
var_dump(is_callable($o));             // __invoke → true
var_dump(is_callable([$o, 'm']));      // [obj, method] → true
var_dump(is_callable([$o, 'nope']));   // → false
var_dump(is_callable(new D()));        // no __invoke → false
var_dump(is_callable(42));             // → false
var_dump(is_callable(null));           // → false
$fcc = myfn(...);                       // first-class-callable → closure
var_dump(is_callable($fcc));           // true
// method_exists (already implemented)
var_dump(method_exists($o, 'm'));      // true
var_dump(method_exists('C', 's'));     // true
var_dump(method_exists($o, 'nope'));   // false
var_dump(method_exists(new D(), 'm')); // false
// closure null-arm is not callable
$n = (1 > 2) ? fn() => 1 : null;
var_dump(is_callable($n));             // false
