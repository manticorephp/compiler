<?php
// get_class_vars() is undefined: it traps with "Call to undefined function" instead of returning the scope-visible default properties.
class A { public $x = 1; protected $y = 2; private $z = 3; public int $t; public static $s = 4; public function inside() { return get_class_vars(self::class); } }
class B extends A { public $w = 5; public function inside2() { return get_class_vars(A::class); } }
echo json_encode(get_class_vars('A')), "\n", json_encode((new A)->inside()), "\n";
echo json_encode(get_class_vars('B')), "\n", json_encode((new B)->inside2()), "\n";
