<?php
// a cell operand (untyped param = cell) in float arithmetic unboxes via
// tagged_to_double instead of bitcasting the NaN-boxed bits.
function half($x) { return $x / 2; }        // non-even -> float in php
echo half(7), "\n";
var_dump(half(7));
var_dump(half(9));

function addf($x) { return $x + 0.5; }
echo addf(3), "\n";
var_dump(addf(2));

function scale($x) { return $x * 1.5; }
var_dump(scale(4));
var_dump(scale(3));

// int|float merge then divided
function pick($b) {
    if ($b) { $n = 10; } else { $n = 3.0; }
    return $n / 4;
}
var_dump(pick(true));
var_dump(pick(false));
