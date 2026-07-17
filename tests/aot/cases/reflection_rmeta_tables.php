<?php

// rmeta's method / property tables and the parent link — the data
// ReflectionClass::hasMethod / hasProperty / getParentClass will read.
//
// Oracles inside the binary: method_exists / property_exists / get_parent_class
// already answer these by folding at compile time, so any disagreement is the
// runtime tables being wrong rather than a php-parity question.

abstract class Animal
{
    public string $name = "x";
    public function speak(): string { return "?"; }
    public function feed(): void {}
}

final class Dog extends Animal
{
    public readonly int $age;
    public function __construct() { $this->age = 3; }
    public function speak(): string { return "woof"; }
    protected function guard(): void {}
    private function secret(): void {}
    public static function make(): Dog { return new Dog(); }
}

// 1 = look in the method table, 0 = the property table.
function hasM(int $h, string $n): bool { return __mc_refl_member($h, $n, 1) !== 0; }
function hasP(int $h, string $n): bool { return __mc_refl_member($h, $n, 0) !== 0; }

$dog = __mc_refl_find("Dog");
$animal = __mc_refl_find("Animal");

// Methods: own, inherited, and absent — agreeing with method_exists.
var_dump(hasM($dog, "speak") === method_exists("Dog", "speak"));
var_dump(hasM($dog, "feed") === method_exists("Dog", "feed"));
var_dump(hasM($dog, "guard") === method_exists("Dog", "guard"));
var_dump(hasM($dog, "make") === method_exists("Dog", "make"));
var_dump(hasM($dog, "nope") === method_exists("Dog", "nope"));

// An inherited method is reachable from the child's table (methodMeta carries
// inherited entries, so no parent walk is needed at runtime).
var_dump(hasM($dog, "feed"));

// Properties: declared and inherited, agreeing with property_exists.
var_dump(hasP($dog, "age") === property_exists("Dog", "age"));
var_dump(hasP($dog, "name") === property_exists("Dog", "name"));
var_dump(hasP($dog, "nope") === property_exists("Dog", "nope"));

// A method is not a property and vice versa — the two tables are distinct.
var_dump(hasP($dog, "speak"));
var_dump(hasM($dog, "age"));

// Flags: readonly is recorded on the property row.
$ro = __mc_refl_member($dog, "age", 0) - 1;
var_dump(($ro & 32) !== 0);
$plain = __mc_refl_member($dog, "name", 0) - 1;
var_dump(($plain & 32) === 0);

// Member flags: visibility is an enum in the low bits; static is a bit.
var_dump((__mc_refl_member($dog, "guard", 1) - 1) & 3);   // protected = 1
var_dump((__mc_refl_member($dog, "secret", 1) - 1) & 3);  // private   = 2
var_dump((__mc_refl_member($dog, "speak", 1) - 1) & 3);   // public    = 0
var_dump(((__mc_refl_member($dog, "make", 1) - 1) & 4) !== 0);
var_dump(((__mc_refl_member($dog, "speak", 1) - 1) & 4) === 0);

// Class flags.
var_dump((__mc_refl_flags($dog) & 1) !== 0);      // Dog is final
var_dump((__mc_refl_flags($animal) & 2) !== 0);   // Animal is abstract
var_dump((__mc_refl_flags($dog) & 2) === 0);

// The parent link, resolved by NAME through the registry.
echo __mc_refl_name(__mc_refl_parent($dog)), "\n";
var_dump(__mc_refl_name(__mc_refl_parent($dog)) === get_parent_class("Dog"));
var_dump(__mc_refl_parent($animal) === 0);
var_dump(__mc_refl_parent($dog) === $animal);

// A null handle is answered, never dereferenced.
var_dump(__mc_refl_member(0, "x", 1) === 0);
var_dump(__mc_refl_flags(0) === 0);
var_dump(__mc_refl_parent(0) === 0);
