<?php
// Several user closures before prelude calls that take callbacks.
$inc = fn(int $x): int => $x + 1;
$dbl = fn(int $x): int => $x * 2;
$neg = fn(int $x): int => -$x;
$even = fn(int $x): bool => $x % 2 === 0;
$xs = array_filter(array_map($inc, range(1, 10)), $even);
usort($xs, fn(int $p, int $q): int => $q <=> $p);
echo implode(',', array_map($dbl, $xs)), ' ', $neg(3), "\n";
