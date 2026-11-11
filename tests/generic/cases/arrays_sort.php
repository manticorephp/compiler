<?php
// Sorting and array helpers with a closure comparator.
$a = [5, 3, 9, 1, 7];
usort($a, fn(int $x, int $y): int => $x <=> $y);
sort($a);
$b = array_map(fn(int $x): int => $x * 2, $a);
$c = array_combine(['a', 'b', 'c', 'd', 'e'], $b);
ksort($c);
echo implode(',', $a), ' ', json_encode($c), "\n";
