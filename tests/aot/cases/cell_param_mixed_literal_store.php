<?php
declare(strict_types=1);

// A CELL param (`array|string`, `mixed`) assigned a literal whose element is a
// cell (a null element, mixed kinds) stays a cell slot: the element-cell seed
// retyped the whole slot to vec[cell], `is_string($x)` folded false and the
// untouched path read the boxed string as an array (`count` = -268435340).

function w(array|string $x): void
{
    if (\is_string($x)) { $x = [true, false, null]; }
    var_dump(count($x), $x);
}

function w2(array|string $x): void
{
    if (\is_string($x)) { $x = [1, 'a']; }
    var_dump(count($x), $x[1]);
}

function w3(mixed $x): void
{
    if (\is_int($x)) { $x = [$x, null]; }
    var_dump(\is_array($x) ? count($x) : $x);
}

w('s');
w([9]);
w2('s');
w2([4, 5]);
w3(1);
w3('str');
w3([1, 2, 3]);
