<?php

// A numeric cell (float|false, int|false, ?float, ?int) compared to a RAW int
// or float must decode BOTH by tag. The old path unboxed the cell as an int,
// reading a boxed double's bits wrongly — mis-ordering and mis-equalling.

function ff(bool $c): float|false { return $c ? 5.0 : false; }
function iff(bool $c): int|false { return $c ? 42 : false; }
function nf(bool $c): ?float { return $c ? 3.5 : null; }
function ni(bool $c): ?int { return $c ? 7 : null; }

$f = ff(true);
var_dump($f > 10);   // false
var_dump($f > 3);    // true
var_dump($f < 10);   // true
var_dump($f == 5);   // true
var_dump($f == 0);   // false
var_dump($f != 0);   // true
var_dump($f >= 5);   // true

$i = iff(true);
var_dump($i == 0);   // false
var_dump($i == 42);  // true
var_dump($i > 100);  // false
var_dump($i < 100);  // true

$x = nf(true);
var_dump($x == 0);   // false
var_dump($x > 3);    // true
var_dump($x < 4);    // true
var_dump($x != 0);   // true

$n = ni(true);
var_dump($n == 7);   // true
var_dump($n > 0);    // true
var_dump($n == 0);   // false

// real float|false source ordered against int
$d = disk_free_space("/");
var_dump($d > 0);
var_dump($d != 0);
