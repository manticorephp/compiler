<?php
final class Point {
    public function __construct(public int $x, public int $y) {}
    public function sum(): int { return $this->x + $this->y; }
}
$p = new Point(3, 4);
echo $p->sum();
