<?php
function f(float $x): float { return $x; }
function g(float $x): float { return $x * 2; }
var_dump(f(5));
var_dump(f(5.5));
$a = 7;
var_dump(f($a));
var_dump(g(3));
var_dump(g(true));
