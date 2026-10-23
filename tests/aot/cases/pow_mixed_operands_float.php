<?php
// pow() of mixed ints answers float(1024) instead of int(1024).
function f(mixed $a, mixed $b) { var_dump(pow($a, $b)); var_dump($a ** $b); }
f(2, 10);
f(2, -1);
f(2.0, 3);
f(2, 0.5);
f('2', '3');
function g(mixed $a, int $b) { var_dump(pow($a, $b)); }
g(3, 4);
function h(int $a, mixed $b) { var_dump(pow($a, $b)); }
h(3, 4);
