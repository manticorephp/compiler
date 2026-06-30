<?php
// subclass-prop object union: a base-typed read of a subclass-only property
// whose declarations are all objects (Dog|Cat) types as union<Dog|Cat> and
// dispatches `->sound()` on the runtime class_id, not the first subclass.
// Also pins a large-int literal round-trip (the compile-time truncation that
// once broke the self-host fixpoint when this union landed).
abstract class Owner {}
class Dog { public function sound(): string { return "woof"; } }
class Cat { public function sound(): string { return "meow"; } }
class DogOwner extends Owner { public function __construct(public Dog $pet) {} }
class CatOwner extends Owner { public function __construct(public Cat $pet) {} }

function petSound(Owner $o): string { return $o->pet->sound(); }

$owners = [new DogOwner(new Dog()), new CatOwner(new Cat()), new DogOwner(new Dog())];
foreach ($owners as $o) { echo petSound($o), "\n"; }

$big = 9223372036854775807;
echo $big % 1000000000000037, "\n";
