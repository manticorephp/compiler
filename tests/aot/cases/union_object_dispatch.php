<?php
// Object union (B|C from a ternary / heterogeneous array) dispatches on the
// runtime class_id, not the then-branch's static class. A shared base method
// (string return) and a common property resolve through the union.
abstract class Shape {
    abstract public function area(): int;
    public function kind(): string { return "shape"; }
}
class Sq extends Shape {
    public function __construct(public int $s) {}
    public function area(): int { return $this->s * $this->s; }
}
class Rect extends Shape {
    public function __construct(public int $w, public int $h) {}
    public function area(): int { return $this->w * $this->h; }
}

$a = (1 < 2) ? new Sq(4) : new Rect(2, 3);   // Sq at runtime
$b = (1 > 2) ? new Sq(4) : new Rect(2, 3);   // Rect at runtime
echo $a->area(), " ", $a->kind(), "\n";       // 16 shape
echo $b->area(), " ", $b->kind(), "\n";       // 6 shape

// heterogeneous object array → element is a union; loop dispatch is virtual
$shapes = [new Sq(3), new Rect(2, 5), new Sq(2), new Rect(1, 4)];
$total = 0;
foreach ($shapes as $sh) { echo $sh->area(), " "; $total += $sh->area(); }
echo "\n", $total, "\n";                       // 9 10 4 4 / 27

function describe(Shape $s): string { return $s->kind() . "=" . $s->area(); }
echo describe($a), " ", describe($b), "\n";    // shape=16 shape=6
