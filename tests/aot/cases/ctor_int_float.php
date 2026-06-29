<?php
// An int argument to a `float` constructor parameter must coerce numerically
// (sitofp), not bitcast the integer bits. The coercion is emitted in emitNewObj;
// it relies on a correctly FLOAT-typed `$expr->value` at the FloatLiteral lower
// (a base-Expr read mistyped it int, so the coercion sitofp'd float bits).
class Box { public function __construct(public float $v) {} }
$i = 5;
$b = new Box($i);
var_dump($b->v);

class Circle { public function __construct(private float $r) {} public function area(): float { return $this->r * $this->r; } }
$shapes = [];
for ($n = 1; $n <= 4; $n++) { $shapes[] = new Circle($n); }
$sum = 0.0;
foreach ($shapes as $s) { $sum += $s->area(); }
printf("%.1f\n", $sum);
