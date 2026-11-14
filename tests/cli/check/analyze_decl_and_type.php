<?php
abstract class A { abstract function f(); }
class B extends A {}
function g(int $x): int { return $x; }
echo g(new stdClass);
