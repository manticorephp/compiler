<?php
// A list return declared array<int, shape> keeps its shape fields through return narrowing: method, record shape, string list.
final class P
{
    /** @return array<int, array{id: int, name: string}> */
    public static function rows(): array
    {
        $out = [];
        $out[] = ['id' => 7, 'name' => 'x'];
        $out[] = ['id' => 9, 'name' => 'y'];
        return $out;
    }
}

/** @return array<int, array{0: string, 1: int}> */
function named(): array
{
    $out = [];
    $out[] = ['b', 2];
    return $out;
}

/** @param int[] $xs */
function find_at(array $xs, int $want): int
{
    foreach ($xs as $i => $x) {
        if ($x === $want) { return $i; }
    }
    return -1;
}

/** @param string[] $xs */
function join2(array $xs): string { return implode('|', $xs); }

$ids = [1];
foreach (P::rows() as $r) { $ids[] = $r['id']; }
$ids[] = 40;
echo find_at($ids, 9), ' ', find_at($ids, 40), "\n";

$names = ['a'];
$nums = [0];
foreach (named() as $p) { $names[] = $p[0]; $nums[] = $p[1]; }
echo join2($names), ' ', find_at($nums, 2), "\n";
