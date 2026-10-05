<?php
// A property store never releases the old value when the property is a method-call receiver
// issue: #24
class A { public static $live = 0; public function __construct() { self::$live++; } public function __destruct() { self::$live--; } public function n() {} }
final class P1 { private A $a; public function run() { $this->a = new A(); $this->a->n(); } }
$p = new P1;
for ($i = 0; $i < 100; $i++) { $p->run(); }
echo A::$live, "\n";
