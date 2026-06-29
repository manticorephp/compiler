<?php
class A {
    public static function create() { return new static(); }
    public function name(): string { return static::class; }
    public function tag() { return "tag-" . static::class; }
}
class B extends A {}
echo A::create()->name(), "\n";      // A
echo B::create()->name(), "\n";      // B
echo A::create()->tag(), "\n";       // tag-A
class W { public function v() { return 9; } }
function mkw() { return new W(); }
echo mkw()->v(), "\n";               // 9
class Q {
    public int $n = 0;
    public function add(int $x) { $this->n += $x; return $this; }
    public function get() { return $this->n; }
}
echo (new Q())->add(3)->add(4)->get(), "\n";   // 7
