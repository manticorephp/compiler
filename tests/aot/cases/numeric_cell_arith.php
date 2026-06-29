<?php
// int|float UNION (numeric cell) in arithmetic promotes at runtime via the
// tagged-arith helpers, matching php's dynamic int-or-float result.

// multi-return int|float
function f(bool $b) { if ($b) return 10; return 2.5; }
var_dump(f(true) + f(false));   // float(12.5)
var_dump(f(true) + f(true));    // int(20)
var_dump(f(false) * f(false));  // float(6.25)
var_dump(f(true) * f(true));    // int(100)
var_dump(f(true) - f(false));   // float(7.5)
echo f(true) + f(false), "\n";  // 12.5

// ternary int|float then arithmetic
$n = (5 > 0) ? 4 : 1.5;         // numeric cell (int here)
var_dump($n * 3);               // int(12)
$m = (5 < 0) ? 4 : 1.5;         // numeric cell (float here)
var_dump($m * 2);               // float(3)

// if/else merge int|float then arithmetic
function g(bool $b) {
    if ($b) { $x = 8; } else { $x = 2.5; }
    return $x + 1;
}
var_dump(g(true));              // int(9)
var_dump(g(false));             // float(3.5)

// numeric cell + concrete int
var_dump(f(false) + 10);        // float(12.5)
var_dump(f(true) + 10);         // int(20)
