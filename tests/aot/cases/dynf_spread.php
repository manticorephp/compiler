<?php
// A dynamic function NAME called with a trailing spread: the pack supplies
// whatever the callee wants past the fixed prefix, and the fixed prefix must be
// evaluated once even though the dispatcher considers many candidates.

function ds_two(int $a, int $b): int { return $a * 100 + $b; }
function ds_three(int $a, int $b, int $c): int { return $a + $b + $c; }
function ds_mix(string $s, int $n): string { return str_repeat($s, $n); }
function ds_one(float $f): float { return $f + 0.5; }
function ds_arr(array $a, int $k): int { return count($a) * $k; }

$calls = 0;
function pfx(): int { global $calls; $calls = $calls + 1; return 3; }

$rest = [4];
$f = "ds_two";
echo $f(...[7, 8]), "\n";
echo $f(pfx(), ...$rest), "\n";
echo $calls, "\n";

$f = "ds_three";
echo $f(...[1, 2, 3]), "\n";

$f = "ds_mix";
echo $f(...["ab", 3]), "\n";

$f = "ds_one";
echo $f(...[1.25]), "\n";

$f = "ds_arr";
echo $f(...[[1, 2, 3], 2]), "\n";
