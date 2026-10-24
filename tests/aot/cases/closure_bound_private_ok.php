<?php
class A { private int $x = 7; private function f() { return 8; } function mk() { return function () { return $this->x + $this->f(); }; } }
$a = new A;
echo ($a->mk())(), "\n";
$g = function () { return $this->x . $this->f(); };
echo Closure::bind($g, $a, A::class)(), "\n";
echo $g->call($a), "\n";
