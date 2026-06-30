<?php
// oop — 2 M polymorphic virtual area() calls over a mixed shape list.
abstract class Shape { abstract public function area(): float; }
class Circle extends Shape {
    public function __construct(private float $r) {}
    public function area(): float { return 3.141592653589793 * $this->r * $this->r; }
}
class Square extends Shape {
    public function __construct(private float $s) {}
    public function area(): float { return $this->s * $this->s; }
}
class Rect extends Shape {
    public function __construct(private float $w, private float $h) {}
    public function area(): float { return $this->w * $this->h; }
}

$shapes = [new Circle(2.0), new Square(3.0), new Rect(4.0, 5.0), new Circle(1.5)];
$n = count($shapes);
$total = 0.0;
for ($i = 0; $i < 2000000; $i++) {
    $total += $shapes[$i % $n]->area();
}
printf("%.4f\n", $total);
