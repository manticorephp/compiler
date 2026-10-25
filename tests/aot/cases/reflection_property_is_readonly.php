<?php
class X { public int $a; public readonly int $b; public function __construct() { $this->b = 1; } }
var_dump((new ReflectionProperty('X', 'a'))->isReadOnly());
var_dump((new ReflectionProperty('X', 'b'))->isReadOnly());
