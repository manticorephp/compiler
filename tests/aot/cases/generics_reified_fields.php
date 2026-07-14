<?php

// Reified generics, part two: the two things a specialization shares with — or
// hides from — its origin.
//
// STATIC PROPERTIES are ONE slot per class in PHP, shared by every binding of
// it. A specialization that registered its own would give each binding a private
// counter (`1` instead of `3` below) — silently. It declares none, so the
// declaring-class walk climbs to the origin, which owns the slot.
//
// A PROPERTY holding a bound container is typed as the specialization only when
// the class also OWNS what goes into it: every store to it must be a `new Box()`.
// `Prices` builds its own, so `$this->box->get(0)` is a direct raw call with no
// boxing. `Loose` is HANDED one from outside — which may be an erased instance —
// so its field stays erased and goes through the thunks. Both must be correct.

/** @template T */
class Box
{
    public static int $made = 0;

    /** @var T[] */
    private array $items = [];

    public function __construct() { self::$made = self::$made + 1; }

    /** @param T $x */
    public function add($x): void { $this->items[] = $x; }

    /** @return T */
    public function get(int $i) { return $this->items[$i]; }
}

final class Prices
{
    /** @var Box<float> */
    private Box $box;

    public function __construct() { $this->box = new Box(); }

    public function put(float $v): void { $this->box->add($v); }
    public function at(int $i): float { return $this->box->get($i); }
}

final class Loose
{
    /** @var Box<float> */
    private Box $box;

    public function __construct(Box $b) { $this->box = $b; }

    public function at(int $i): float { return $this->box->get($i); }
}

$p = new Prices();
$p->put(1.5);
$p->put(2.25);
echo $p->at(0) + $p->at(1), "\n";

// Built outside, with no binding: an erased instance flowing into a bound field.
$outside = new Box();
$outside->add(9.5);
$l = new Loose($outside);
echo $l->at(0), "\n";

/** @var Box<int> $i */
$i = new Box();
$i->add(1125899906842624);
echo $i->get(0), "\n";

// One counter across the erased class and every specialization of it:
// Prices' box, $outside, and $i.
echo Box::$made, "\n";
