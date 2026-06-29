<?php
class A { public function cls(): string { return get_class($this); } }
class B extends A {}
class C extends B {}
echo (new A())->cls(), "\n";
echo (new B())->cls(), "\n";
echo (new C())->cls(), "\n";
$objs = [new A(), new B(), new C()];
foreach ($objs as $o) { echo get_class($o), " "; }
echo "\n";
