<?php
class T { public $v; public $arg; function __construct($arg = null) { $this->arg = $arg; } }
class A { function f(string $c, array $args = []): mixed { $o = new $c(); $o->__construct(...$args); return $o; } }
function mk(bool $b): A|false { return $b ? new A() : false; }
echo json_encode(mk(true)->f('T', ['k'])), "\n";
