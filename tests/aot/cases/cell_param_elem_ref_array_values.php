<?php
declare(strict_types=1);

// An element reference into a CELL param (`$r = &$x[0]`) keeps the slot a
// cell: the element-reference seed retyped the slot to vec[cell] and
// array_values read the boxed int as a buffer pointer (SIGSEGV).

function v(array|int $x): void
{
    if (\is_int($x)) { $x = [$x, 2]; }
    $r = &$x[0];
    $r = 9;
    echo implode(",", array_values($x)), "\n";
    var_dump($x[0] + $x[1]);
}

v(1);
v([4, 5, 6]);
