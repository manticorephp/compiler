<?php
interface Animal { public function sound(): string; public function legs(): int; }
class Dog implements Animal { public function sound(): string { return "woof"; } public function legs(): int { return 4; } }
class Cat implements Animal { public function sound(): string { return "meow"; } public function legs(): int { return 4; } }
class Bird implements Animal { public function sound(): string { return "tweet"; } public function legs(): int { return 2; } }
// heterogeneous array of unrelated classes sharing an interface
$zoo = [new Dog(), new Cat(), new Bird()];
foreach ($zoo as $a) { echo $a->sound(), " "; }
echo "\n";
echo $zoo[0]->sound(), $zoo[2]->sound(), "\n";   // index access
$total = 0;
foreach ($zoo as $a) { $total += $a->legs(); }
echo $total, "\n";                                // 10

// abstract base with float-returning method, polymorphic collection
abstract class Shape { abstract public function area(): float; }
class Sq extends Shape { public function __construct(private float $s) {} public function area(): float { return $this->s * $this->s; } }
class Tri extends Shape { public function __construct(private float $b, private float $h) {} public function area(): float { return 0.5 * $this->b * $this->h; } }
$shapes = [new Sq(3.0), new Tri(4.0, 6.0)];
foreach ($shapes as $s) { echo $s->area(), " "; }
echo "\n";                                        // 9 12
