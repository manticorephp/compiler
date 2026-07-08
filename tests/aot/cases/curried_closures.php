<?php
// A closure returning a closure (`fn($x) => fn($y) => ...`) must NOT be inlined
// as an arrow (NodeClone can't copy a closure node) — keep the real invoke.
$mul = fn($x) => fn($y) => $x * $y;
echo $mul(3)(4), "\n";
$double = $mul(2);
echo $double(5), " ", $double(6), "\n";

// Explicit closure with `use`.
$adder = function ($x) { return function ($y) use ($x) { return $x + $y; }; };
echo $adder(10)(5), "\n";

// Returned from a function, typed callable.
function adder(int $base): callable { return fn($n) => $base + $n; }
$add10 = adder(10);
echo $add10(1), " ", $add10(2), "\n";

// Higher-order compose.
$compose = fn(callable $f, callable $g) => fn($x) => $f($g($x));
$inc = fn($n) => $n + 1;
$sq = fn($n) => $n * $n;
echo ($compose($inc, $sq))(4), "\n";
