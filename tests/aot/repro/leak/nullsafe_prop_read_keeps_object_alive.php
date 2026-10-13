<?php
// Null-safe property read of an object property keeps the object alive past its last owner.
// issue: #206
final class D { public function __construct(public int $n = 0) {} public function __destruct() { echo "destruct\n"; } }
final class B { public ?D $o = null; }
$c = new B();
$c->o = new D(7);
echo ($c->o?->n ?? 0), "\n";
$c->o = null;
echo "after\n";
