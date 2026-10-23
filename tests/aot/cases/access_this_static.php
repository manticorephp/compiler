<?php
class A {
    public static function f() { return $this; }
    public static function g() { return isset($this) ? 'this' : 'nothis'; }
    public function h() { return fn() => isset($this); }
    public static function s() { return static fn() => 1; }
}
try { A::f(); } catch (Error $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
echo A::g(), "\n";
echo (new A)->h()() ? 'y' : 'n', "\n";
echo A::s()(), "\n";
