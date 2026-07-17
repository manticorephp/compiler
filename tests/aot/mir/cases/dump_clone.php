<?php
class Point {
    public function __construct(public int $x, public int $y) {}
}
function dup(Point $p): Point {
    $q = clone $p;
    return $q;
}
echo dup(new Point(1, 2))->x;
