<?php
class A { public function g() { return [1]; } }
$x = [];
var_dump(isset($x[0], $x[0]->g()[0]));
$y = [new A];
var_dump(isset($y[0], $y[0]->g()[0]));
var_dump(isset($y[0], $y[1], $y[0]->g()[0]));
$n = null;
var_dump(isset($n, $n->p));
