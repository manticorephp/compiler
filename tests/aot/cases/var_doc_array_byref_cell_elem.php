<?php
// An int[] local that a later array_splice widens to cell elements keeps ONE element repr on every path, so an int[] callee reads its own values.
/** @param int[] $kind */
function find40(array $kind): int { foreach ($kind as $j => $k) { if ($k === 40) { return $j; } } return -1; }
/** @return int[] */
function src(): array { return [355, -5, 7, 40]; }
function f(): void
{
    /** @var int[] $kind */
    $kind = [];
    foreach (src() as $t) {
        if ($t < 0) { $kind[] = -1; continue; }
        if ($t === 7) {
            foreach ([1, 2] as $_) { $kind[] = 262; }
        }
        $kind[] = $t;
    }
    echo implode(',', $kind), ' ', find40($kind), ' ', var_export($kind[1] === -1, true), "\n";
    array_splice($kind, 1, 2, [9]);
    echo implode(',', $kind), ' ', find40($kind), "\n";
}
f();
/** @param array<string, int> $m */
function sum(array $m): int { $s = 0; foreach ($m as $v) { $s += $v; } return $s; }
function g(): void
{
    /** @var array<string, int> $m */
    $m = [];
    foreach (['a' => 1, 'b' => -2, 'c' => 40] as $k => $v) {
        if ($v < 0) { $m[$k] = 0; continue; }
        $m[$k] = $v;
    }
    echo sum($m), ' ', json_encode($m), "\n";
    array_splice($m, 0, 1);
    echo sum($m), ' ', json_encode($m), "\n";
}
g();
