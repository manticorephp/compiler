<?php
// Spreading an array into a variadic Closure parameter crashes (SIGSEGV) instead of passing the elements.
function one(Closure $f, array $a) { return $f(...$a); }
echo one(fn(...$p) => implode(",", $p), [2, 3]), "\n";
echo one(fn(int ...$p) => array_sum($p), [2, 3, 4]), "\n";
echo one(fn(...$p) => count($p), []), "\n";
$g = fn($a, ...$r) => $a . ":" . implode(",", $r);
echo $g(0, ...[1, 2]), "\n";
