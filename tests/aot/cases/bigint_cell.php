<?php
// A 64-bit int survives a cell round-trip (var_dump / mixed param / heterogeneous
// array element / arithmetic) — before, the 48-bit NaN-box payload truncated any
// int >= 2^47.
var_dump((int)"999999999999999");
var_dump(9223372036854775807);
var_dump(PHP_INT_MAX);
var_dump(1000000000000000000);
$x = (int)"888888888888888";
var_dump($x);
var_dump($x === 888888888888888);
var_dump($x + 1);
function id($v): mixed { return $v; }
var_dump(id(777777777777777));
$arr = [PHP_INT_MAX, 42, "hi"];
foreach ($arr as $e) { var_dump($e); }
