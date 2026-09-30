<?php
declare(strict_types=1);

// A lone CELL operand of min()/max() is the array-of-elements form: the int
// path unboxed the array the cell holds and answered its address. A non-array
// or empty one throws as php does.

function mm(array|int $v): void
{
    if (\is_int($v)) { $v = [3, $v, 4]; }
    var_dump(max($v), min($v));
}

function mixedArg(mixed $m): void
{
    try {
        var_dump(max($m));
    } catch (\Throwable $e) {
        echo \get_class($e), ': ', $e->getMessage(), "\n";
    }
    try {
        var_dump(min($m));
    } catch (\Throwable $e) {
        echo \get_class($e), ': ', $e->getMessage(), "\n";
    }
}

mm(9);
mm([7, 5]);
mixedArg(['b', 'a', 'c']);
mixedArg([2.5, 1, 3]);
mixedArg([]);
mixedArg(5);

function lone(): void
{
    foreach ([static fn () => max('abc'), static fn () => min(5)] as $f) {
        try {
            var_dump($f());
        } catch (\Throwable $e) {
            echo \get_class($e), ': ', $e->getMessage(), "\n";
        }
    }
}

lone();
