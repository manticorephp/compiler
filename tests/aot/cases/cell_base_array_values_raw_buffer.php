<?php
declare(strict_types=1);

// A `array|string` param narrowed by `$v = explode(…)` / preg_split is a CELL
// channel holding a RAW string buffer: array_values must decode each word by
// the buffer's hint (sebastian/diff's Differ::diffToArray fed the LCS denormal
// floats, every `===` failed, and php-cs-fixer's --diff lost every common line).

/** @param array<int, mixed> $a */
function lcsCount(array $a, array $b): int
{
    $n = 0;
    for ($i = 0; $i < \count($a); $i++) {
        for ($j = 0; $j < \count($b); $j++) {
            if ($a[$i] === $b[$j]) { $n++; }
        }
    }
    return $n;
}

function split(string $s): array
{
    $r = preg_split('/(.*\R)/', $s, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
    return $r === false ? [] : $r;
}

function diffish(array|string $from, array|string $to): int
{
    if (\is_string($from)) { $from = split($from); }
    if (\is_string($to)) { $to = split($to); }
    return lcsCount(array_values($from), array_values($to));
}

function show(array $a): void { var_dump($a[0], $a[\count($a) - 1]); }

function strs(array|string $v): void
{
    if (\is_string($v)) { $v = explode(',', $v); }
    show(array_values($v));
}

function ints(array|int $n): void
{
    if (\is_int($n)) { $n = range($n, $n + 3); }
    show(array_values($n));
    var_dump(array_values($n)[1]);
}

function floats(array|float $n): void
{
    if (\is_float($n)) { $n = [$n, $n * 2]; }
    show(array_values($n));
}

function holes(array|string $v): void
{
    if (\is_string($v)) { $v = explode(',', $v); }
    unset($v[0]);
    show(array_values($v));
}

echo diffish("a\nb\nc\n", "b\nc\nd\n"), "\n";
echo diffish(["a\n", "b\n"], ["b\n"]), "\n";
strs('a,b,c');
strs(['x', 'y']);
ints(3);
ints([7, 5]);
floats(1.5);
floats([0.5, 0.25]);
holes('p,q,r');
