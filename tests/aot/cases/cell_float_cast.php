<?php
function f(mixed $x): float { return (float)$x; }
var_dump(f(3));
var_dump(f("2.5"));
var_dump(f(4.5));
var_dump(f(true));
