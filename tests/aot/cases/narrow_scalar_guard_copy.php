<?php
function idx(mixed $x, int $n): int {
    if (\is_int($x)) { $i = $x; } elseif (\is_float($x) || \is_bool($x)) { $i = (int)$x; } else { return -2; }
    if ($i < 0 || $i >= $n) { return -1; }
    return $i;
}
function f(mixed $x): string {
    if (is_int($x) && $x > 2) { return "big " . ($x * 2); }
    if (is_float($x)) { return "f " . ($x / 2); }
    if (is_bool($x)) { return $x ? "T" : "F"; }
    return "other";
}
function g(mixed $x): mixed {
    if (is_int($x)) { $x = $x + 1; return $x; }
    return $x;
}
function h(mixed $x): mixed {
    if (is_int($x)) { $f = function () use ($x) { return $x * 3; }; return $f(); }
    return 0;
}
echo idx(3, 10), idx(12, 10), idx("a", 10), idx(2.9, 10), idx(true, 10), "\n";
echo f(5), " ", f(1), " ", f(3.0), " ", f(false), " ", f("s"), "\n";
echo g(4), " ", h(5), "\n";
