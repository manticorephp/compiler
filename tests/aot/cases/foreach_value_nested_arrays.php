<?php
// A foreach value that is itself an array of arrays. The loop took the value
// with a buffer-only retain while the variable's scope-exit release walked the
// nested buffers too (and on every release, not only the last) — so each call
// dropped the inner arrays the CALLER's literal still owned. Under glibc the
// freed blocks were quietly reused; musl's allocator refused the second free.

function walk(array $rows): int
{
    $n = 0;
    foreach ($rows as $row) { $n += count($row); }
    return $n;
}

/** @param array<int, array<string, int[]>> $rows */
function walkTyped(array $rows): int
{
    $n = 0;
    foreach ($rows as $row) { foreach ($row as $inner) { $n += count($inner); } }
    return $n;
}

function walkVariadic(array $first, array ...$rest): int
{
    $n = count($first);
    foreach ($rest as $row) { $n += count($row); }
    return $n;
}

$rows = [['a' => [1, 2]], ['b' => [3]]];
for ($i = 0; $i < 3; $i++) {
    echo walk($rows), " ", walkTyped($rows), " ", walkVariadic(['x' => 1], ['a' => [3]], ['b' => [4, 5]]), "\n";
    $noise = [];
    for ($j = 0; $j < 50; $j++) { $noise[] = [$j, $j + 1]; }
}
print_r($rows);
echo array_sum(array_map('count', $rows[0])), "\n";
print_r(array_merge_recursive(['a' => [1, 2]], ['a' => [3]]));
print_r(array_merge_recursive(['a' => 'x'], ['a' => ['y', 'z']]));
