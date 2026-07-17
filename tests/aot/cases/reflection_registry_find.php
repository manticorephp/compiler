<?php

// The name -> rmeta registry, built by @llvm.global_ctors at startup.
//
// This is the one lookup no compile-time fold can do: the name is a runtime
// string. The probe that chose this mechanism also proved its failure mode is
// SILENT — a registry that never filled returns 0 for everything, exits 0, and
// looks like "class not found". So these assertions check the registry is
// actually POPULATED, not merely that lookups don't crash.

class Base { public int $b = 1; }
final class Dog extends Base {}
class Cat extends Base {}

function h(string $n): int { return __mc_refl_find($n); }

// Populated: a known class resolves to a non-zero handle.
var_dump(h("Dog") !== 0);
var_dump(h("Cat") !== 0);
var_dump(h("Base") !== 0);

// And it is the SAME block the object route reaches — the two paths into rmeta
// (by name, and through an instance's descriptor) must agree.
$d = new Dog();
$c = new Cat();
var_dump(h("Dog") === __mc_refl_of($d));
var_dump(h("Cat") === __mc_refl_of($c));

// Distinct classes get distinct blocks.
var_dump(h("Dog") !== h("Cat"));

// Round-trip: name -> handle -> name.
echo __mc_refl_name(h("Dog")), "\n";
echo __mc_refl_name(h("Base")), "\n";

// A miss is 0, and 0 is not a wild pointer.
var_dump(h("NoSuchClass") === 0);
var_dump(__mc_refl_name(h("NoSuchClass")) === "");

// Lookup is repeatable — the list is walked, never consumed.
var_dump(h("Dog") === h("Dog"));

// A prelude class registers too: the registry spans everything compiled in,
// not just user code.
var_dump(h("RuntimeException") !== 0);

// A LITERAL passed straight in, not through a string-typed param. Different
// operand shape entirely: a constant is already a `getelementptr` constexpr of
// type ptr, where a param arrives as a runtime i64. Wrapping every lookup in
// h() hid this path once already.
var_dump(__mc_refl_find("Dog") !== 0);
var_dump(__mc_refl_find("NoSuchClass") === 0);
echo __mc_refl_name(__mc_refl_find("Cat")), "\n";
