<?php
// A foreach KEY binding of a name that enters the loop a cell: the slot leaves
// the body raw unless it is boxed back, and every read past the loop is by tag.
/** @param array<string,int> $h @param array<int,mixed> $m */
function keyExit(array $h, array $m, int $run): string
{
    $k = $m[1];
    if ($run === 1) { foreach ($h as $k => $x) { if ($x > 5) { break; } } }
    return 'k=' . $k . ' ' . \gettype($k);
}
/** @param array<int,string> $h @param array<int,mixed> $m */
function valExit(array $h, array $m): string
{
    $v = $m[0];
    foreach ($h as $v) { }
    return 'v=' . $v . ' ' . \gettype($v);
}
echo keyExit(['ab' => 1, 'cd' => 9, 'ef' => 2], [0, 7], 1), "\n";
echo keyExit(['ab' => 1], [0, 7], 0), "\n";
echo valExit(['x', 'yz'], [3]), "\n";
