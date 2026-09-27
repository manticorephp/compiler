<?php
abstract class T { public function run(): string { return method_exists($this, 'processToken') ? 'has' : 'none'; } }
final class A extends T { public function processToken(): void {} }
final class B extends T {}
class C extends T { public function PROCESSTOKEN(): void {} }
final class D extends C {}
foreach ([new A(), new B(), new C(), new D()] as $o) { echo get_class($o), ' ', $o->run(), "\n"; }
