<?php
// A float with an integral value passed to an int parameter reaches the callee as its raw IEEE bits.
// issue: #298
function f(int $n): int { return $n; }
$n = 1000;
echo f($n / 2), "\n";
echo f(10.0), "\n";
