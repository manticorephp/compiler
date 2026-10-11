<?php
// A call passing cell (mixed) values to a typed variadic param packs them raw: the callee reads tagged words as ints.
// issue: #184
function f(int $x, int ...$m): int { return $x + array_sum($m); }
function g(mixed $a): mixed { return f($a[0], $a[1], $a[2]); }
echo g([1, 2, 3]), "\n";
