<?php
declare(strict_types=1);

// array_first / array_last over a CELL-typed base that holds a RAW buffer
// decode the element by the buffer's hint (they handed a string pointer out as
// a denormal float); current / reset / end already did.

function ends(array|string $v): void
{
    if (\is_string($v)) { $v = explode(',', $v); }
    var_dump(array_first($v), array_last($v), reset($v), end($v));
}

function nums(array|int $v): void
{
    if (\is_int($v)) { $v = range($v, $v + 1); }
    var_dump(array_first($v), array_last($v));
}

ends('a,b,c,d');
ends(['x', 'y']);
nums(3);
nums([7, 5]);
