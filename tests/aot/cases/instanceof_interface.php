<?php
interface Shape { public function area(): int; }
class Box implements Shape {
    public int $a;
    public function __construct(int $a) { $this->a = $a; }
    public function area(): int { return $this->a * $this->a; }
}
$b = new Box(5);
echo ($b instanceof Shape) ? "y" : "n", ",", ($b instanceof Box) ? "y" : "n";
