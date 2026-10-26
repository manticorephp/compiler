<?php
class W2 { public $arg; public $o; function __construct($arg = null, $o = 'od') { $this->arg = $arg; $this->o = $o; } }
class A { function f(string $c, array $args = []): mixed { $o = new $c(); $o->__construct(...$args); return $o; } }
echo json_encode((new A())->f('W2', ['o' => 'named'])), "\n";
