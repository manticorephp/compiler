<?php
// cell→concrete coercion at boundaries: a `mixed` element returned as int,
// and a `mixed` formatted with sprintf %s (stringify by tag).
function first(mixed $a): int { return $a[0]; }
echo first([10, 20, 30]), "\n";

function fmt(mixed $x): string { return sprintf("[%s]", $x); }
echo fmt("hi"), "\n";
echo fmt(9), "\n";

class Bag { private mixed $d; public function __construct(array $x){ $this->d = $x; } public function at(int $i): int { return $this->d[$i]; } }
$b = new Bag([7, 8, 9]);
echo $b->at(1), "\n";
