<?php
abstract class Shape {
    abstract public function area(): float;
    abstract public function name(): string;
    public function describe(): string { return $this->name() . "=" . $this->area(); }
}
class Square extends Shape {
    public function __construct(private float $side) {}
    public function area(): float { return $this->side * $this->side; }
    public function name(): string { return "square"; }
}
class Circle extends Shape {
    public function __construct(private float $r) {}
    public function area(): float { return 3.14 * $this->r * $this->r; }
    public function name(): string { return "circle"; }
}
echo (new Square(4.0))->describe(), "\n";
echo (new Circle(2.0))->describe(), "\n";
var_dump(new Square(1.0) instanceof Shape);
