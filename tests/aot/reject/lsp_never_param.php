<?php
class A { public function f(callable $a) {} }
class B extends A { public function f(Closure $a) {} }
