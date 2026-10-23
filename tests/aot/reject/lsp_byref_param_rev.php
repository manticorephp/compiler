<?php
class A { public function f(array &$a) {} }
class B extends A { public function f(array $a) {} }
