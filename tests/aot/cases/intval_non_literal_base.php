<?php
// intval($s, $base) with a non-literal base ignores the base and answers 0.
function f(mixed $s, int $base) { var_dump(intval($s, $base)); }
f('ff', 16);
f('12', 8);
f('1e3', 10);
f(42, 16);
f(4.7, 2);
function g(string $s, int $base) { var_dump(intval($s, $base)); }
g('1e3', 10);
g('0x1A', 16);
g('0x1A', 0);
