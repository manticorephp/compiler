<?php
namespace App;

use Lib\Thing as T;
use function Lib\helper;

$a = "x {$o->m(new Nope, new T)}";
$b = "y {$o->k(helper(1), other(2))}
 and {$o->z(Foo::BAR)}";
