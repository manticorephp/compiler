<?php
declare(strict_types=1);

// min(...$p) / max(...$p): a one-element pack is the single-array form over
// THAT element (`min(...[[5, 2]])` is 2); two or more compete themselves.

function pk(array $p): void
{
    try {
        var_dump(min(...$p), max(...$p));
    } catch (\Throwable $e) {
        echo \get_class($e), ': ', $e->getMessage(), "\n";
    }
}

var_dump(min(...[[5, 2]]), max(...[[5, 9]]));
pk([[4, 1]]);
pk([3, 8]);
pk([[1, 2], [1, 3]]);
pk([7]);
pk([]);
var_dump(max(...array_map(fn(int $x): int => $x * 2, [1, 5, 3])));
