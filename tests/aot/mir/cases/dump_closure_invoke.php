<?php
function adder(int $n): callable {
    return function (int $x) use ($n): int {
        return $x + $n;
    };
}
function run(int $base, int $arg): int {
    $f = adder($base);
    return $f($arg);
}
echo run(10, 5);
