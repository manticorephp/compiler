<?php
// An array of Ffi\Ptr holds raw addresses, not counted objects: when the array
// dies it must not release (free) its elements — the program frees them itself.

/** @return array<int,\Ffi\Ptr> */
function cells(int $n): array
{
    $out = [];
    for ($i = 0; $i < $n; $i++) { $out[$i] = \Runtime\Libc\malloc(16); }
    return $out;
}

/** @param array<int,\Ffi\Ptr> $cells */
function fill(array $cells): int
{
    $sum = 0;
    foreach ($cells as $i => $p) {
        \poke_i64($p, 0, $i * 10);
        $sum += \peek_i64($p, 0);
    }
    return $sum;
}

function run(): int
{
    $c = cells(4);
    $copy = $c;
    $copy[] = \Runtime\Libc\malloc(16);
    $sum = fill($c) + fill($copy);
    foreach ($copy as $p) { \Runtime\Libc\free($p); }
    return $sum;
}

for ($k = 0; $k < 3; $k++) { echo run(), "\n"; }
echo "ok\n";
