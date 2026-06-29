<?php
// captureless single-expr arrow at a direct invoke → inlined
$sq = fn($x) => $x * $x;
echo $sq(7), "\n";

// string-returning arrow (concat) — also inlinable
$greet = fn($n) => "hi " . $n . "!";
echo $greet("bob"), "\n";

// two params
$add = fn($a, $b) => $a + $b;
echo $add(3, 4), "\n";

// param used twice with a non-trivial arg must stay correct
$dbl = fn($x) => $x + $x;
$v = 21;
echo $dbl($v), "\n";

// captured closure must NOT inline but still work
$base = 100;
$shift = fn($x) => $x + $base;
echo $shift(5), "\n";

// closure in a loop (the hot path)
$f = fn($x) => $x * 3;
$s = 0;
for ($i = 0; $i < 10; $i++) { $s += $f($i); }
echo $s, "\n";

// ternary body
$sign = fn($x) => $x > 0 ? "pos" : "nonpos";
echo $sign(5), " ", $sign(-2), "\n";
