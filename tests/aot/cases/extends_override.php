<?php
class Shape {
    public function area(): int { return 0; }
    public function label(): string { return "shape:" . $this->area(); }
}
class Square extends Shape {
    public int $side;
    public function __construct(int $side) { $this->side = $side; }
    public function area(): int { return $this->side * $this->side; }
}
$s = new Square(4);
echo $s->area(), ",", $s->label();
