<?php
// print_r of an object prints no properties
class A { public $x = 1; protected $y = 'p'; private $z = [1]; }
print_r(new A);
