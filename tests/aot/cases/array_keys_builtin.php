<?php
// array_keys codegen builtin — uniform NaN-boxed keys for plain + cell sources.
$a = ["x" => 1, "y" => 2, "z" => 3];
var_dump(array_keys($a));
foreach (array_keys($a) as $k) { echo $k, "\n"; }

$b = [10, 20, 30];
var_dump(array_keys($b));

var_dump(array_keys([]));

// mixed int + string keys (the canonical SPL/ArrayObject case).
$c = ["a" => 1];
$c[] = 99;
$c["b"] = 2;
var_dump(array_keys($c));

// array_keys over a `mixed`/cell property.
class Box {
    private mixed $d;
    public function __construct(array $x) { $this->d = $x; }
    public function keys(): array { return array_keys($this->d); }
}
$box = new Box(["alpha" => 1, "beta" => 2]);
var_dump($box->keys());
