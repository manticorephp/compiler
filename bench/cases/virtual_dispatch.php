<?php
// Polymorphic method dispatch through a base-class reference. Reps seeded from
// $argc. (Built via if/else, not a ternary-of-two-subclasses — that form
// currently mis-types the array element and dispatches statically.)
abstract class Shape { abstract public function area(): int; }
class Sq extends Shape {
    public function __construct(private int $s) {}
    public function area(): int { return $this->s * $this->s; }
}
class Rect extends Shape {
    public function __construct(private int $w, private int $h) {}
    public function area(): int { return $this->w * $this->h; }
}
$shapes = [];
$len = 1000 * $argc;
for ($i = 0; $i < $len; $i++) {
    if (($i & 1) === 0) { $shapes[] = new Sq($i); }
    else { $shapes[] = new Rect($i, $i + 1); }
}
$sum = 0;
$reps = 50000 * $argc;
for ($r = 0; $r < $reps; $r++) {
    foreach ($shapes as $s) { $sum += $s->area(); }
}
echo $sum, "\n";
