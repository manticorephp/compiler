<?php
// PHP `==` on two objects of the SAME class compares them property by property
// (loosely); `===` stays identity. Different classes are never `==`.
class P { public int $x; public string $s;
  function __construct(int $x, string $s) { $this->x = $x; $this->s = $s; } }
class Q { public int $x; public string $s;
  function __construct(int $x, string $s) { $this->x = $x; $this->s = $s; } }
class R { public float $f; public bool $b; public array $xs;
  function __construct(float $f, bool $b, array $xs) { $this->f = $f; $this->b = $b; $this->xs = $xs; } }

$a = new P(1, "a");
$b = new P(1, "a");
$c = new P(2, "a");
$d = new P(1, "b");
$q = new Q(1, "a");
$e = $a;
var_dump($a == $b, $a === $b, $a != $b, $a !== $b);
var_dump($a == $c, $a == $d, $a == $q);
var_dump($a === $e, $a == $e, $a !== $e);

// Property types beyond int/string: float, bool, and an array property
// (compared by value, recursively).
$r1 = new R(1.5, true, [1, 2]);
$r2 = new R(1.5, true, [1, 2]);
$r3 = new R(1.5, true, [1, 3]);
$r4 = new R(2.5, true, [1, 2]);
var_dump($r1 == $r2, $r1 == $r3, $r1 == $r4);

// A nullable object holder: null is never == an object.
$maybe = null;
var_dump($maybe == $a);
