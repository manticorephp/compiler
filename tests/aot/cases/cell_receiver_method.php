<?php
// A method call on a cell/mixed receiver (no static class) resolves the return
// type when every implementer agrees — string results render correctly.
interface Named { public function name(): string; }
interface Aged  { public function age(): int; }
class Person implements Named, Aged {
    public function __construct(private string $n, private int $a) {}
    public function name(): string { return $this->n; }
    public function age(): int { return $this->a; }
}

function maybe(bool $b): (Named&Aged)|null {
    return $b ? new Person("Eve", 28) : null;
}
$x = maybe(true);
echo $x === null ? "none" : $x->name(), "\n";
echo $x === null ? "-" : (string)$x->age(), "\n";

class Animal { public function speak(): string { return "woof"; } public function legs(): int { return 4; } }
function get(): mixed { return new Animal(); }
$a = get();
echo $a->speak(), " has ", $a->legs(), " legs\n";
