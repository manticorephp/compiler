<?php
// const-fold overflow -> float
var_dump(9223372036854775807 + 1);
var_dump(9223372036854775807 * 2);
var_dump(-9223372036854775807 - 2);
var_dump(-(-9223372036854775807 - 1));
// const non-overflow stays int
var_dump(3 + 4);
var_dump(1000000 * 1000000);
// int|float union (numeric cell) overflow -> float
function ovf_add(int|float $x, int|float $y): int|float { return $x + $y; }
function ovf_mul(int|float $x, int|float $y): int|float { return $x * $y; }
function ovf_sub(int|float $x, int|float $y): int|float { return $x - $y; }
var_dump(ovf_add(5000000000000000000, 5000000000000000000));
var_dump(ovf_mul(5000000000, 5000000000));
var_dump(ovf_sub(-9000000000000000000, 2000000000000000000));
// union non-overflow stays int
var_dump(ovf_add(3, 4));
var_dump(ovf_mul(1000, 1000));
