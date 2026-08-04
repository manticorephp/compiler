<?php
// funcarr — array_map / array_filter / array_reduce pipeline (closure fusion).
$src = [];
for ($i = 0; $i < 1000; $i++) { $src[$i] = $i; }
$acc = 0;
$reps = 2000 * $argc;
for ($iter = 0; $iter < $reps; $iter++) {
    $doubled = array_map(fn(int $x): int => $x * 2, $src);
    $evens = array_filter($doubled, fn(int $x): bool => ($x % 4) === 0);
    $acc += array_reduce($evens, fn(int $c, int $x): int => $c + $x, 0);
}
echo $acc, "\n";
