<?php
// Recursive fibonacci — call overhead + integer arithmetic.
// Depth/reps seeded from $argc (=1) so the optimizer can't fold the result.
function fib(int $n): int { return $n < 2 ? $n : fib($n - 1) + fib($n - 2); }
$s = 0;
$reps = 10 * $argc;
$depth = 31 + $argc;
for ($i = 0; $i < $reps; $i++) { $s += fib($depth); }
echo $s, "\n";
