<?php
// Unary minus must unbox a mixed/numeric-cell operand before negating
// (negating the raw NaN-boxed bits produced garbage).
function m($x) { if ($x < 0) return -$x; return $x; }   // untyped (cell) param
echo m(9), " ", m(-6), "\n";                            // 9 6

function nn(int|float $x) { return -$x; }               // numeric cell
echo nn(3), " ", nn(2.5), " ", nn(-8), "\n";            // -3 -2.5 8

function abs2($x) { return $x < 0 ? -$x : $x; }         // mixed via ternary
echo abs2(-15), " ", abs2(15), "\n";                    // 15 15

// negation feeding arithmetic and a mixed array element
$vals = [-1, -2, -3];
$sum = 0;
foreach ($vals as $v) { $sum += -$v; }
echo $sum, "\n";                                        // 6
