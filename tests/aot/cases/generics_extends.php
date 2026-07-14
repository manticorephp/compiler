<?php

// `@extends Base<T>`: the generic method is declared on the BASE, while the
// receiver binds the CHILD's parameters. Climbing the chain re-maps the
// arguments, so `Bag<float>` reaches `Base` as `Base<float>`.
//
// float is the discriminating case: a string survives erasure because a cell
// carries its tag, but a plain mixed cell takes the INTEGER arithmetic path, so
// an unbound float summed to 0.

/** @template T */
abstract class Base
{
    /** @var T[] */
    protected array $items = [];

    /** @param T $x */
    public function add($x): void { $this->items[] = $x; }

    /** @return T */
    public function get(int $i) { return $this->items[$i]; }

    public function count(): int { return \count($this->items); }
}

/**
 * @template T
 * @extends Base<T>
 */
final class Bag extends Base {}

final class Tag
{
    public function __construct(public readonly string $name) {}
}

/** @var Bag<float> $f */
$f = new Bag();
$f->add(1.5);
$f->add(2.25);
echo $f->get(0) + $f->get(1), "\n";

/** @var Bag<int> $i */
$i = new Bag();
$i->add(10);
$i->add(32);
echo $i->get(0) + $i->get(1), "\n";

/** @var Bag<string> $s */
$s = new Bag();
$s->add('ab');
$s->add('cd');
echo $s->get(0) . $s->get(1), "\n";

/** @var Bag<Tag> $t */
$t = new Bag();
$t->add(new Tag('alpha'));
echo $t->get(0)->name, "\n";

echo $f->count(), $i->count(), $s->count(), $t->count(), "\n";
