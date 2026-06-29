<?php
var_dump(max(1, 2.5));
var_dump(max(3, 1.5));
var_dump(min(3, 1));
var_dump(min(2.5, 1.5));
var_dump(max(1, 2, 3.5, 2));
var_dump(max(2.5, 1));
echo max(1, 2.5), "\n";
function f(mixed $x): float { return max($x, 1.5); }
var_dump(f(3.0));
var_dump(f(0.5));
