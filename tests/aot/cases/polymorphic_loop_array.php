<?php
// A heterogeneous object array built in a LOOP via if/else must keep its element
// type as the common base (obj<Shape>), not erase to unknown on the back-edge
// merge. Erasure made `$s->area()` (a float virtual return) read raw bits.
abstract class Shape { abstract public function area(): float; }
class Circle extends Shape { public function __construct(private float $r) {} public function area(): float { return 3.14159 * $this->r * $this->r; } }
class Square extends Shape { public function __construct(private float $s) {} public function area(): float { return $this->s * $this->s; } }

$shapes = [];
for ($i = 1; $i <= 6; $i++) {
    if ($i % 2) { $shapes[] = new Circle((float)$i); } else { $shapes[] = new Square((float)$i); }
}
$total = 0.0;
foreach ($shapes as $s) { echo get_class($s), " "; $total += $s->area(); }
echo "\n";
printf("%.4f\n", $total);
