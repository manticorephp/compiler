<?php
abstract class A { public function f(int $a) {} }
abstract class B extends A { abstract public function f(string $a); }
