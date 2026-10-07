<?php
// Two subclasses fill one inherited slot with different element kinds: one
// sibling's evidence must not become the other's claim.
abstract class Root { public array $x = []; }
final class A extends Root { public function __construct(array $x) { $this->x = $x; } }
final class B extends Root { public function __construct(array $x) { $this->x = $x; } }
$b = new B([false, true]);
$a = new A([1.5, 2.5]);
var_dump($a->x[0], $b->x[1], $a->x);
function first(Root $r): mixed { return $r->x[0]; }
var_dump(first($a), first($b));
