<?php

// `@template T of Animal` is not an analyzer's check here — it changes the
// emitted code. An UNBOUND T knows nothing about its value, so it must erase to
// a tagged cell (the value carries its own type at runtime). A BOUNDED T is known
// to be an object, so it erases to obj<Animal>: a raw pointer, no boxing. The same
// program with the bound removed emits 9 boxing/tagging ops; with it, zero.
//
// `@template T = int` supplies the binding when a use site names none.

abstract class Animal
{
    public function __construct(public readonly string $name) {}
    abstract public function speak(): string;
    public function legs(): int { return 4; }
}

final class Cat extends Animal
{
    public function speak(): string { return $this->name . ' meows'; }
}

final class Bird extends Animal
{
    public function speak(): string { return $this->name . ' tweets'; }
    public function legs(): int { return 2; }
}

/** @template T of Animal */
final class Pen
{
    /** @var T[] */
    private array $items = [];

    /** @param T $x */
    public function add($x): void { $this->items[] = $x; }

    /** @return T */
    public function get(int $i) { return $this->items[$i]; }

    public function count(): int { return \count($this->items); }
}

/** @template T = int */
final class Counter
{
    /** @var T[] */
    private array $items = [];

    /** @param T $x */
    public function add($x): void { $this->items[] = $x; }

    /** @return T */
    public function get(int $i) { return $this->items[$i]; }
}

/** @var Pen<Cat> $cats */
$cats = new Pen();
$cats->add(new Cat('tom'));
$cats->add(new Cat('felix'));

/** @var Pen<Bird> $birds */
$birds = new Pen();
$birds->add(new Bird('tweety'));

echo $cats->get(0)->speak(), "\n";
echo $cats->get(1)->name, "\n";
echo $birds->get(0)->speak(), "\n";
echo $cats->get(0)->legs() + $birds->get(0)->legs(), "\n";
echo $cats->count(), $birds->count(), "\n";

// no `<...>` at the use site — the `= int` default binds T
$c = new Counter();
$c->add(10);
$c->add(32);
echo $c->get(0) + $c->get(1), "\n";
