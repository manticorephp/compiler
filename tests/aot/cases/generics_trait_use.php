<?php

// `@use Items<float>` — a generic TRAIT.
//
// This is the one place generics buy speed for free. A generic CLASS has ONE
// compiled body serving every instantiation, so `T` must stay erased and its
// values ride as tagged cells. A TRAIT is COPIED into each class that uses it, so
// the binding can be substituted at the source: `T` never becomes a type variable
// at all, it lowers straight to `float`, and every member comes out concrete.
//
// Same program, measured: the generic-class form emits 37 boxing/tag ops; this
// one emits ZERO.

/** @template T */
trait Items
{
    /** @var T[] */
    private array $items = [];

    /** @param T $x */
    public function add($x): void { $this->items[] = $x; }

    /** @return T */
    public function get(int $i) { return $this->items[$i]; }

    public function count(): int { return \count($this->items); }
}

final class Tag
{
    public function __construct(public readonly string $name) {}
}

final class Floats { /** @use Items<float> */ use Items; }
final class Names  { /** @use Items<string> */ use Items; }
final class Counts { /** @use Items<int> */ use Items; }
final class Tags   { /** @use Items<Tag> */ use Items; }

$f = new Floats();
$f->add(1.5);
$f->add(2.25);
echo $f->get(0) + $f->get(1), "\n";
echo $f->get(1) * 2.0, "\n";

$n = new Names();
$n->add('ab');
$n->add('cd');
echo $n->get(0) . $n->get(1), "\n";
echo strlen($n->get(1)), "\n";

$c = new Counts();
$c->add(10);
$c->add(32);
echo $c->get(0) + $c->get(1), "\n";

$t = new Tags();
$t->add(new Tag('alpha'));
echo $t->get(0)->name, "\n";

echo $f->count(), $n->count(), $c->count(), $t->count(), "\n";
