<?php
// get_class() of `$obj ?? new Other` folds to the static class of the left operand
class A { public ?A $c = null; function get(): ?A { return $this->c; } }
$a = new A;
$c = $a->get();
echo get_class($c ?? new stdClass), "\n";
