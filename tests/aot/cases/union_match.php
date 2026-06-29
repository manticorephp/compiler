<?php
function m(int $n): int|float|string {
    return match(true) {
        $n > 10 => "big",
        $n > 0 => 1.5,
        default => $n,
    };
}
echo m(20), "\n";
echo m(5), "\n";
echo m(-3), "\n";
var_dump(m(20));
var_dump(m(5));
var_dump(m(-3));
