<?php
// A local holding one of several classes, merged with a cell on the other path, is boxed before the merge.
class A { public $v = 'a'; public function w(): A { return $this; } }
final class B extends A { public function w(): static { return $this; } }
class Z {}
function cv(): mixed { return new B; }
function h(): B { return new B; }
function t2(?A $r, bool $c) { $x = $r ?? h(); if ($c) { $x = cv(); } return get_class($x); }
function t4(?A $r, bool $c) { $x = $r ?? h(); if ($c) { $x = 5; } return gettype($x); }
function t5(?A $r, bool $c) { $x = $r ?? h(); if ($c) { $x = $x->w(); } return get_class($x); }
function t6(?A $r, bool $c) { $x = $c ? new A : new B; if ($r) { $x = cv(); } return get_class($x); }
function t7(?A $r, bool $c) { $x = $r ?? h(); if ($c) { $x = new Z; } return get_class($x); }
function t8(?A $r, bool $c) { $x = $r ?? h(); if ($c) { $x = new B; } return $x->v . get_class($x); }
function t9(?A $r, bool $c): array { $x = $r ?? h(); if ($c) { $x = $x->w(); } return (array)$x; }
foreach ([false, true] as $c) {
  echo t2(null, $c), t2(new A, $c), t4(null, $c), t5(null, $c), t5(new A, $c), t6(null, $c), t6(new A, $c), t7(new A, $c), t8(new A, $c), json_encode(t9(null, $c)), "\n";
}
