<?php
// array_map / array_filter via prelude injection (callback invoked in-module,
// not across the stdlib.o boundary which crashes). Int element/result cases.
function dbl_each(array $a): array {
    return array_map(fn($x) => $x * 2, $a);
}

echo implode(",", array_map(fn($x) => $x * 2, [1, 2, 3, 4])), "\n";
echo implode(",", array_filter([1, 2, 3, 4, 5, 6], fn($x) => $x % 2 === 0)), "\n";
echo array_sum(array_map(fn($x) => $x * $x, [1, 2, 3, 4])), "\n";
echo implode(",", dbl_each([10, 20, 30])), "\n";

$evens = array_filter([1, 2, 3, 4, 5, 6, 7, 8], fn($x) => $x % 2 === 0);
echo count($evens), "\n";
echo array_sum($evens), "\n";
