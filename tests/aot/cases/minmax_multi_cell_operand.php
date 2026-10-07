<?php
declare(strict_types=1);

// n-ary min()/max() with an operand that is not a number (a cell holding an
// array or a string, null, a bool, a string beside an int) is ordered by php's
// full comparison. The int path unboxed an array operand and answered its
// ADDRESS.

function z(array|int $a, array|int $b): void
{
    if (\is_int($a)) { $a = [$a]; }
    var_dump(max($a, $b), min($a, $b));
}

function u(mixed $m): void
{
    var_dump(max($m, 2), min($m, 2), max(1, $m, 0));
}

function n(?int $x): void
{
    var_dump(max($x, -5), min($x, 3));
}

z(1, [0]);
z([3], 2);
u([5]);
u(7);
u('abc');
u(null);
u(2.5);
n(null);
n(4);
var_dump(max('abc', 2), min('10', 9), max(true, 2), min([1, 2], [1, 3]), max('b', 'a', 'c'));
var_dump(max(1, 2.5), min(3, 1.5, 2), max(4, 9, 2));
