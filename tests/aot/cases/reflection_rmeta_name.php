<?php

// Reflection Tier-2, the data path: object -> class descriptor -> rmeta -> name.
// The oracle is get_class() IN THE SAME BINARY — it resolves the class through
// the long-trusted route, so any disagreement is the new metadata being wrong
// rather than a php-parity question.

interface Speaks {}

class Base { public int $b = 1; }

final class Dog extends Base implements Speaks
{
    public string $name = "rex";
    public function __destruct() {}
}

class Cat extends Base implements Speaks {}

function nameOf(object $o): string
{
    return __mc_refl_name(__mc_refl_of($o));
}

$d = new Dog();
$c = new Cat();
$b = new Base();

var_dump(nameOf($d) === get_class($d));
var_dump(nameOf($c) === get_class($c));
var_dump(nameOf($b) === get_class($b));

echo nameOf($d), "\n";
echo nameOf($c), "\n";
echo nameOf($b), "\n";

// An interface-typed receiver: the name must come from the RUNTIME class, not
// the static type — this is the case a compile-time fold would get wrong.
$s = $d;
var_dump(nameOf($s) === "Dog");
$s = $c;
var_dump(nameOf($s) === "Cat");

// A handle is a plain address, so it is stable per class and shared by
// instances — two Dogs reflect to the same block.
$d2 = new Dog();
var_dump(__mc_refl_of($d) === __mc_refl_of($d2));
var_dump(__mc_refl_of($d) !== __mc_refl_of($c));
var_dump(__mc_refl_of($d) !== 0);

// A 0 handle answers "" rather than faulting.
var_dump(__mc_refl_name(0) === "");
