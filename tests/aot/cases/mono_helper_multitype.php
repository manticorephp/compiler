<?php
// Monomorphization Phase 1: one generic helper used over two element
// types specializes per call group (head$mono$p0_vec_int / _str).
function head(array $a) { return $a[0]; }
function lastOf(array $a) { return $a[count($a) - 1]; }

echo head([10, 20, 30]), "\n";
echo head(["alpha", "beta"]), "\n";
echo lastOf([1, 2, 3]), "\n";
echo lastOf(["x", "y", "z"]), "\n";
