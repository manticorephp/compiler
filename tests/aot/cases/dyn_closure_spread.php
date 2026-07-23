<?php
function apply(callable $cb, array $args) {
    return $cb(...$args);
}
$sum = fn($a, $b, $c) => $a + $b + $c;
echo apply($sum, [1, 2, 3]), "\n";
$cat = fn(string $x, string $y) => $x . $y;
echo apply($cat, ["foo", "bar"]), "\n";
echo call_user_func_array($sum, [10, 20, 30]), "\n";
$mul = fn($a, $b) => $a * $b;
$vals = [6, 7];
echo apply($mul, $vals), "\n";
