<?php
// ++/-- on a local whose kind is only known at run time (a ?int from a call,
// a mixed value) follows php: the number moves, null++ is 1, a numeric string
// moves numerically, a float string becomes a float. And a match
// with a `null` arm beside value arms is a nullable cell, not raw words.

function prv(int $i): ?int { return $i > 0 ? $i - 1 : null; }

function pick(int $k): mixed
{
    return match ($k) { 0 => null, 1 => '7', 2 => 2.5, 3 => '1e1', default => 10 };
}

$f = new SplFixedArray(5);
for ($i = 0; $i < 5; $i++) { $f[$i] = "v$i"; }

$p = prv(4);
echo $f[--$p], "\n";
var_dump($p);
$p = prv(4);
echo $f[$p--], "\n";
var_dump($p);
$p = prv(4);
$p++;
var_dump($p);
$n = prv(0);
$n++;
var_dump($n);
var_dump(pick(0), pick(1), pick(2), pick(3), pick(4));
for ($k = 0; $k < 5; $k++) {
    $v = pick($k);
    $v++;
    var_dump($v);
}
foreach ([1, 2, 4] as $k) {
    $w = pick($k);
    $w--;
    var_dump($w);
}

function walk(?int $idx): int
{
    $steps = 0;
    while ($idx !== null && $idx > 0) {
        --$idx;
        $steps++;
    }
    return $steps;
}
var_dump(walk(3), walk(null));
