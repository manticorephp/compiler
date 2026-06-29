<?php
// array_values codegen builtin — cell/mixed source + typed sources (re-boxed).
class Bag {
    private mixed $d;
    public function __construct(array $x) { $this->d = $x; }
    public function vals(): array { return array_values($this->d); }
}
// cell/mixed source (heterogeneous values copied as-is).
$b = new Bag(["x" => 10, "y" => "hi", "z" => 3.5]);
var_dump($b->vals());
foreach ($b->vals() as $v) { echo $v, "\n"; }
var_dump((new Bag([]))->vals());

// plain typed sources re-index + re-box per element kind.
var_dump(array_values([10, 20, 30]));
var_dump(array_values(["a" => "p", "b" => "q"]));
var_dump(array_values([1.5, 2.5]));
