<?php

// A local bound on both arms of an if/else to arrays of ONE kind rides its slot
// raw past the merge. It used to keep a cell promotion an earlier inference run
// planted while one arm still read as a cell (a `static $cache = null` returned
// through a bare `array`, a bare `array` param's element): each pass through
// the merge then rebuilt the array as a fresh cell array and boxed it into a
// slot whose raw predecessor nothing released. Pure-PHP inflate lost its whole
// Huffman table that way on every call (~19 KB). memory_get_usage() answers
// the peak RSS (ru_maxrss) here, which a leak can only raise: 100 000 calls of
// the smallest leak below are 16 MB, the bound is 3 MB. @serial: a memory
// measurement.

/** @param callable(int): int $body */
function measure(string $label, callable $body): void
{
    $sum = 0;
    for ($i = 0; $i < 2000; $i = $i + 1) {
        $sum = $sum + $body($i);
    }
    $before = memory_get_usage();
    for ($i = 0; $i < 100000; $i = $i + 1) {
        $sum = $sum + $body($i);
    }
    $growth = memory_get_usage() - $before;
    echo $label, ': sum=', $sum, ' ', $growth < 3 * 1024 * 1024 ? 'growth ok' : 'growth=' . round($growth / 1048576, 1) . 'MB', "\n";
}

function build(int $n): array
{
    $c = [];
    $s = [];
    for ($i = 0; $i < $n; $i++) {
        $c[$i] = $i;
        $s[$i] = $i * 2;
    }
    return [$c, $s];
}

function tables(): array
{
    static $cache = null;
    if ($cache !== null) { return $cache; }
    $cache = [build(40), build(30)];
    return $cache;
}

function decode(array $h): int
{
    $count = $h[0];
    $symbol = $h[1];
    return $count[3] + $symbol[4];
}

function decodeRef(array &$st, array $h): int
{
    $st[0] = $st[0] + 1;
    return count($h[0]);
}

$tabs = [build(40), build(30)];

final class Tabs
{
    /** @var array<int,array<int,array<int,int>>> */
    public static array $t = [];
}
Tabs::$t = [build(40), build(30)];

measure('static cache', function (int $i): int {
    if ($i % 2 === 0) {
        $t = tables();
        $lh = $t[0];
        $dh = $t[1];
    } else {
        $lh = build(10);
        $dh = build(12);
    }
    return decode($lh) + decode($dh);
});
measure('static cache by-ref', function (int $i): int {
    $st = [0];
    if ($i % 2 === 0) {
        $t = tables();
        $lh = $t[0];
    } else {
        $lh = build(10);
    }
    return decodeRef($st, $lh) + $st[0];
});
measure('param element', function (int $i): int {
    return pick(tables(), $i);
});
measure('global', function (int $i): int {
    global $tabs;
    if ($i % 2 === 0) { $lh = $tabs[0]; } else { $lh = build(10); }
    return decode($lh);
});
measure('static prop', function (int $i): int {
    if ($i % 2 === 0) { $lh = Tabs::$t[0]; } else { $lh = build(10); }
    return decode($lh);
});
measure('returned', function (int $i): int {
    return decode(choose($i));
});

function pick(array $x, int $i): int
{
    if ($i % 2 === 0) { $lh = $x[0]; } else { $lh = [[1, 2, 3, 4], [5, 6, 7, 8, 9]]; }
    return decode($lh);
}

function choose(int $i): array
{
    if ($i % 2 === 0) { $t = tables(); $lh = $t[1]; } else { $lh = build(10); }
    return $lh;
}
echo "done\n";
