<?php

// A function whose returns are objects of different classes. NarrowReturns was
// built for bare-`array` erasure and only ever narrowed arrays, so such a
// function kept an `unknown` return — and the caller could resolve nothing on the
// result: a string from `->speak()` came back as a raw pointer. The return is now
// the UNION of the classes it can be, which dispatches on the runtime class_id.

final class Cat
{
    public function __construct(public readonly string $n) {}
    public function speak(): string { return $this->n . ' meows'; }
    public function legs(): int { return 4; }
}

final class Dog
{
    public function __construct(public readonly string $n) {}
    public function speak(): string { return $this->n . ' barks'; }
    public function legs(): int { return 4; }
}

final class Bird
{
    public function __construct(public readonly string $n) {}
    public function speak(): string { return $this->n . ' tweets'; }
    public function legs(): int { return 2; }
}

// two arms
function pick(bool $b) { return $b ? new Cat('tom') : new Dog('rex'); }

// three arms, through statements rather than a ternary
function pick3(int $i)
{
    if ($i === 0) { return new Cat('c'); }
    if ($i === 1) { return new Dog('d'); }
    return new Bird('b');
}

// a single class still narrows to that class, not a union
function only() { return new Cat('solo'); }

// via a dynamically named class
function make(string $cls) { return new $cls('dyn'); }

echo pick(true)->speak(), "\n";
echo pick(false)->speak(), "\n";
echo pick3(0)->speak(), ' ', pick3(1)->speak(), ' ', pick3(2)->speak(), "\n";
echo pick3(2)->legs() + pick(true)->legs(), "\n";
echo only()->speak(), ' ', only()->n, "\n";
echo make('Dog')->speak(), "\n";
echo make(Bird::class)->speak(), "\n";
