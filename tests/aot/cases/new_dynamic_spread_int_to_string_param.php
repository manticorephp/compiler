<?php
// new $cls(...$args) with an int element bound to a string constructor parameter segfaults instead of coercing it
class U { public function __construct(public string $a = 'x', public int $b = 1) {} }
function mk(string $c, array $args) { return new $c(...$args); }
$o = mk('U', [1, 2]);
var_dump($o->a, $o->b);
