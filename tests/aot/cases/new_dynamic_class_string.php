<?php

// `new $cls(args)` — the class is named by a value, so the emitter compares the
// name against every class whose constructor takes this many arguments. The
// result is typed as the UNION of those candidates, which is what lets a method
// call on it dispatch (an `unknown` receiver resolves no return type, and a
// string result came back as a raw pointer).
//
// This is what a `class-string<T>` factory stands on.

final class Cat
{
    public function __construct(public readonly string $name, public readonly int $legs) {}
    public function speak(): string { return $this->name . ' meows'; }
}

final class Dog
{
    public function __construct(public readonly string $name, public readonly int $legs) {}
    public function speak(): string { return $this->name . ' barks'; }
}

/**
 * @template T
 * @param class-string<T> $cls
 * @return T
 */
function make(string $cls, string $name)
{
    return new $cls($name, 4);
}

$k = 'Cat';
$a = new $k('tom', 4);
echo $a->name, ' ', $a->legs, "\n";
echo $a->speak(), "\n";

// the class chosen at runtime, in a loop
foreach (['Cat', 'Dog'] as $which) {
    $o = new $which('x', 2);
    echo $o->speak(), ' ', $o->legs, "\n";
}

// a class-string<T> factory: the property reads resolve through the runtime
// class_id. (A METHOD call on a factory's result needs a union RETURN type,
// which no function carries yet — a separate, pre-existing gap.)
$c = make(Cat::class, 'felix');
$d = make(Dog::class, 'rex');
echo $c->name, ' ', $d->name, "\n";
echo $c->legs + $d->legs, "\n";
