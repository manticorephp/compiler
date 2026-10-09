<?php
// in_array over int / float / bool vecs: hit, miss, strict and loose, NAN, -0.0, hashed values, a cellified buffer.
$i = [];
for ($k = 0; $k < 40; $k++) { $i[] = $k * 3 - 7; }
$i[] = PHP_INT_MAX;
$i[] = PHP_INT_MIN;
var_dump(in_array(-7, $i), in_array(-7, $i, true), in_array(110, $i), in_array(0, $i), in_array(-8, $i, true));
var_dump(in_array(PHP_INT_MAX, $i, true), in_array(PHP_INT_MIN, $i), in_array(PHP_INT_MAX - 1, $i, true));
$n = 5;
var_dump(in_array($n + 1, $i), in_array($n + 2, $i, true));

$f = [];
for ($k = 0; $k < 20; $k++) { $f[] = $k + 0.5; }
$f[] = -0.0;
$f[] = NAN;
$f[] = 1.0E+300;
var_dump(in_array(0.5, $f), in_array(19.5, $f, true), in_array(20.5, $f), in_array(0.0, $f), in_array(-0.0, $f, true));
var_dump(in_array(NAN, $f), in_array(NAN, $f, true), in_array(1.0E+300, $f, true), in_array(0.25, $f));

$b = [];
$b[] = false;
$b[] = false;
var_dump(in_array(true, $b), in_array(false, $b, true));
$b[] = true;
var_dump(in_array(true, $b, true));

$e = [];
$e[] = 1;
array_pop($e);
var_dump(in_array(1, $e), in_array(1, $e, true));

$m = ["x" => 10, "y" => 20, "z" => 30];
var_dump(in_array(20, $m), in_array(25, $m, true));
unset($m["y"]);
var_dump(in_array(20, $m), in_array(30, $m, true));

$s = 0;
$big = [];
for ($k = 0; $k < 5000; $k++) { $big[] = $k * 2; }
for ($k = 0; $k < 400; $k++) { if (in_array($k, $big, true)) { $s++; } }
echo $s, "\n";
$fb = [];
for ($k = 0; $k < 3000; $k++) { $fb[] = $k * 0.5; }
$s = 0;
for ($k = 0; $k < 300; $k++) { if (in_array($k * 0.5, $fb)) { $s++; } }
echo $s, "\n";

/** @param int[] $xs */
function has(array $xs, int $n): bool { return in_array($n, $xs, true); }
$mixed = [1, "2", 3];
var_dump(has([4, 5, 6], 5), has([4, 5, 6], 7), has([], 1));
