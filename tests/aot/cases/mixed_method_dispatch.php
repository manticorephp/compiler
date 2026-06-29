<?php
// Method dispatch on a `mixed`/cell receiver — unbox to the raw object ptr so
// $this + class_id virtual dispatch read the object, not the boxed bits.
interface Shape { public function area(): int; }
class Sq implements Shape { public function __construct(private int $s){} public function area(): int { return $this->s * $this->s; } }
class Rect implements Shape { public function __construct(private int $w, private int $h){} public function area(): int { return $this->w * $this->h; } }
function describe(mixed $s): int { return $s->area(); }
echo describe(new Sq(4)), "\n";
echo describe(new Rect(3, 5)), "\n";

class Adder { public function add(int $a, int $b): int { return $a + $b; } }
function go(mixed $x): int { return $x->add(10, 20); }
echo go(new Adder()), "\n";

// mixed field holding an object, method called through it
class Holder { public mixed $obj; public function __construct(mixed $o){ $this->obj = $o; } public function run(): int { return $this->obj->area(); } }
$h = new Holder(new Sq(6));
echo $h->run(), "\n";
