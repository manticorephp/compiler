<?php
// An int appended from a shape element of a function result (array{0: int, 1: string}) makes the int list compare unequal to its own values.
/** @return array<int, array{0: int, 1: string}> */
function pairs(): array
{
    $out = [];
    $out[] = [319, 'A'];
    return $out;
}

/** @param int[] $kind */
function find(array $kind): int
{
    foreach ($kind as $j => $k) {
        if ($k === 40) {
            return $j;
        }
    }
    return -1;
}

$kind = [355];
foreach (pairs() as $p) {
    $kind[] = $p[0];
}
$kind[] = 40;
echo find($kind), "\n";
