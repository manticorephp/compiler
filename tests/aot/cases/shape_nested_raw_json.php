<?php
/** @param array<int, array{0:int,1:int}> $pairs */
function sum(array $pairs): int
{
    $t = 0;
    foreach ($pairs as $p) {
        $t += $p[0] * $p[1];
    }
    return $t;
}

echo sum(json_decode('[[1,2],[3,4]]', true)), "\n";
echo sum([[1, 2], [3, 4]]), "\n";
$rows = [[1, 2], [3, 4]];
echo sum($rows), "\n";
try { echo sum(json_decode('[[1,"a"]]', true)), "\n"; }
catch (TypeError $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
