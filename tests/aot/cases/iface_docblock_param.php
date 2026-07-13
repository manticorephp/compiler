<?php
/** @template T */
interface Coll {
    /** @param T $x */ public function add($x): void;
    /** @return T */ public function get(int $i);
}
final class Dog { public function __construct(public readonly string $name) {} }
/** @implements Coll<Dog> */
final class DogColl implements Coll {
    /** @var Dog[] */ private array $items = [];
    /** @param Dog $x */ public function add($x): void { $this->items[] = $x; }
    /** @return Dog */ public function get(int $i) { return $this->items[$i]; }
}
/** @var Coll<Dog> $c */
$c = new DogColl();
$c->add(new Dog('rex'));
echo $c->get(0)->name, "\n";
