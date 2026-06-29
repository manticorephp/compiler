<?php
class Point {
    public int $x;
    public int $y;
    public function __construct(int $x, int $y) {
        $this->x = $x;
        $this->y = $y;
    }
    public function sum(): int {
        return $this->x + $this->y;
    }
}
$p = new Point(3, 4);
echo $p->sum();
