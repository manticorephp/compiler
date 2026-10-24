<?php
class A { public function f(string $x): string { return $x; } }
class B extends A { public function f(int $x): string { return "a"; } }
