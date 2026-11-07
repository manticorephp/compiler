<?php
// A class declared inside an `if` gives its child no inherited slots, so the child's constructor writes past the object
class P {
    private string $name;
    private int $n;
    public function __construct(string $name) { $this->name = $name; $this->n = strlen($name); }
    public function get(): string { return $this->name . ':' . $this->n; }
}
if (!class_exists('C', false)) {
    class C extends P {}
}
class D extends C {}
class E2 extends D { public string $tag = 'e'; }
echo (new D('abc'))->get(), "\n";
echo (new E2('xy'))->get(), "\n";
