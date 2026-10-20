<?php
// Whole-array stores of different element reprs into one local: concrete vs concrete, assoc vs cell-assoc.
/** @return int[] */
function ints(): array { return [1, 2, 3]; }
/** @return string[] */
function strs(): array { return ['x', 'y']; }
/** @return array<string, int> */
function amap(): array { return ['a' => 1, 'b' => 2]; }
/** @return array<string, mixed> */
function mmap(): array { return ['a' => 'one', 'b' => [2]]; }
function f(bool $s): string
{
    $p = ints();
    if ($s) { $p = strs(); }
    $p[0] = 9;
    return \implode(',', $p);
}
function g(bool $s): string
{
    $m = amap();
    if ($s) { $m = mmap(); }
    $m['c'] = 3;
    return \json_encode($m);
}
echo f(false), ' ', f(true), "\n";
echo g(false), ' ', g(true), "\n";
