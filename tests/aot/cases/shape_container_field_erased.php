<?php
// A declared shape's container field claims raw elements only as deep as the
// value filling it proves: an erased (cell-element) list must not be read raw.

/** @return array<string, int[]> */
function grouped(): array
{
    $by = [];
    foreach (['a', 'b', 'a'] as $i => $w) {
        $by[$w][] = $i;
    }
    return $by;
}

/** @return array{0: array<string, int[]>, 1: int} */
function viaReturn(): array
{
    $by = [];
    foreach (['a', 'b', 'a'] as $i => $w) {
        $by[$w][] = $i;
    }
    return [$by, 3];
}

/** @param array{0: array<string, int[]>, 1: int} $t */
function viaParam(array $t): void
{
    foreach ($t[0]['a'] ?? [] as $occ) { echo $occ, ' '; }
    $v = $t[0]['a'];
    echo $v[0] + $v[1], ' ', $t[1], "\n";
}

/** @param list<array{0: int[], 1: string}> $rows */
function viaNested(array $rows): void
{
    foreach ($rows as $r) {
        $xs = $r[0];
        echo $r[1], '=', $xs[0] + $xs[1], ' ';
    }
    echo "\n";
}

[$by, $n] = viaReturn();
foreach ($by['a'] ?? [] as $occ) { echo $occ, ' '; }
$v = $by['a'];
echo $v[0] + $v[1], ' ', $n, "\n";

$g = [];
foreach (['a', 'b', 'a'] as $i => $w) { $g[$w][] = $i; }
viaParam([$g, 4]);

$rows = [];
foreach (['x', 'y'] as $j => $name) {
    $l = [];
    $l[] = $j + 10;
    $l[] = $j + 20;
    $rows[] = [$l, $name];
}
$m = [];
foreach (['p', 'q'] as $j => $name) { $m[$name][] = $j; $m[$name][] = $j + 1; }
$rows[] = [$m['p'], 'p'];
viaNested($rows);
echo count(grouped()), "\n";
