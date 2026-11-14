<?php
// new, instanceof and static calls through a name created by class_alias() at run time do not resolve: Class "B" not found.
// issue: #653
class A { public static function who(): string { return static::class; } }
class_alias("A", "B");
$b = new B();
var_dump($b instanceof A, $b instanceof B, B::who(), get_class($b));
