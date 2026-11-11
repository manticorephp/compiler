<?php
// Reflection over user classes.
interface Shape { public function area(): float; }
final class Square implements Shape { public function __construct(private float $s) {} public function area(): float { return $this->s ** 2; } }
final class Circle implements Shape { public function __construct(private float $r) {} public function area(): float { return 3.14 * $this->r ** 2; } }
foreach ([new Square(2), new Circle(1)] as $s) {
    $rc = new ReflectionClass($s);
    echo $rc->getShortName(), ' ', $s->area(), ' ', count($rc->getMethods()), "\n";
}
