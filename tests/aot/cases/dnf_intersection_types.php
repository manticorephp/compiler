<?php
// Intersection (A&B): parse + dispatch across all the intersected interfaces.
// DNF ((A&B)|null): parse + null-check + instanceof on the nullable union.
interface Named { public function name(): string; }
interface Aged  { public function age(): int; }

class Person implements Named, Aged {
    public function __construct(private string $n, private int $a) {}
    public function name(): string { return $this->n; }
    public function age(): int { return $this->a; }
}

// Pure intersection — full method dispatch (string + int returns).
function describe(Named&Aged $p): string {
    return $p->name() . " (" . $p->age() . ")";
}
echo describe(new Person("Bob", 30)), "\n";
echo describe(new Person("Ada", 36)), "\n";

// DNF nullable union — parses; null-check and instanceof narrow it.
function maybe(bool $b): (Named&Aged)|null {
    return $b ? new Person("Eve", 28) : null;
}
$m = maybe(true);
var_dump($m === null);
var_dump($m instanceof Person);
if ($m instanceof Person) { echo $m->name(), "\n"; }
$n = maybe(false);
var_dump($n === null);
