<?php
// get_class_vars() is undefined: it traps with "Call to undefined function" instead of returning the scope-visible default properties.
// issue: #265
class A { public $x = 1; protected $y = 2; private $z = 3; public int $t; public static $s = 4; public function inside() { return get_class_vars(self::class); } }
echo json_encode(get_class_vars('A')), "\n", json_encode((new A)->inside()), "\n";
