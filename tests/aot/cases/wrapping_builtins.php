<?php
use function Manticore\wrapping_add;
use function Manticore\wrapping_sub;
use function Manticore\wrapping_mul;

var_dump(wrapping_add(PHP_INT_MAX, 1));
var_dump(wrapping_add(PHP_INT_MIN, -1));
var_dump(wrapping_add(40, 2));
var_dump(wrapping_sub(PHP_INT_MIN, 1));
var_dump(wrapping_sub(0, PHP_INT_MIN));
var_dump(wrapping_sub(5, 7));
var_dump(wrapping_mul(PHP_INT_MAX, 2));
var_dump(wrapping_mul(PHP_INT_MIN, -1));
var_dump(wrapping_mul(-7046029254386353131, -7046029254386353131));
var_dump(wrapping_mul(-3, 7));
$a = PHP_INT_MAX; $b = 3;
var_dump(wrapping_add($a, $b), wrapping_mul($a, $b), wrapping_sub(-$a, $b));
// ranges that need the 64-bit unsigned path
mt_srand(42);
var_dump(mt_rand(PHP_INT_MIN, PHP_INT_MAX) !== null, mt_rand(-5, 5) >= -5);
mt_srand(7);
echo mt_rand(PHP_INT_MIN, 0), " ", mt_rand(0, PHP_INT_MAX), " ", mt_rand(-10, PHP_INT_MAX), "\n";
// float printing walks the Ryu path
echo json_encode([0.1, 1.5e300, 5e-324, 123456789.125, 1e22, 4.35]), "\n";
