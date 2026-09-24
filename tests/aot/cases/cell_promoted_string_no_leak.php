<?php

// A string / object local that is raw on some paths and a cell on others (the
// if/else merge promotes it with a self-boxing `$x = box($x)`), or a cell for
// the whole function (a loop re-kinds it), must release exactly what it owns
// on every overwrite, return and scope exit. Both used to be blocked outright:
// every call leaked the whole string (~2.5x its size), and every loop overwrite
// dropped the previous value on the floor. memory_get_usage() answers the peak
// RSS here, which a leak can only raise: 20 000 calls of a 4 KB string are
// >80 MB, the bound is 4 MB. @serial: a memory measurement.

/** @param callable(int): int $body */
function measure(string $label, callable $body): void
{
    $sum = 0;
    for ($i = 0; $i < 500; $i = $i + 1) {
        $sum = $sum + $body($i);
    }
    $before = memory_get_usage();
    for ($i = 0; $i < 20000; $i = $i + 1) {
        $sum = $sum + $body($i);
    }
    $growth = memory_get_usage() - $before;
    echo $label, ': sum=', $sum, ' ', $growth < 4 * 1024 * 1024 ? 'growth ok' : 'growth=' . round($growth / 1048576, 1) . 'MB', "\n";
}

final class Box
{
    public string $s;
    public function __construct(string $s) { $this->s = $s; }
}

function dec(string $p): ?string
{
    return strlen($p) > 100000 ? null : strrev($p);
}

function decBox(string $p): ?Box
{
    return strlen($p) > 100000 ? null : new Box(strrev($p));
}

// The repro: `.=`-built string, null-merged ternary on a path that never runs.
function readAppend(string $p, bool $compressed, bool $none): string
{
    $data = '';
    $data .= $p;
    if ($compressed) {
        $plain = $none ? null : dec($data);
        if ($plain === null) {
            return '';
        }
        $data = $plain;
    }
    return $data;
}

// `=` instead of `.=`, and the branch taken.
function readAssign(string $p, bool $compressed): string
{
    $data = $p . '!';
    if ($compressed) {
        $plain = strlen($p) > 1000000 ? null : dec($data);
        if ($plain === null) {
            return '';
        }
        $data = $plain;
    }
    return $data;
}

// Fall-through instead of return: the value leaves through a strlen.
function lenFall(string $p, bool $k): int
{
    $data = str_repeat($p, 2);
    if ($k) {
        $data = strlen($p) > 1000000 ? null : $p . '?';
    }
    $n = strlen((string)$data);
    return $n;
}

// int|string: the other arm is an int.
function intOrString(string $p, bool $k): int
{
    $v = $p . '#';
    if ($k) {
        $v = strlen($p);
    }
    return is_int($v) ? $v : strlen($v);
}

// ?Obj.
function readObj(string $p, bool $k): int
{
    $o = new Box($p . '.');
    if ($k) {
        $b = decBox($p);
        if ($b === null) {
            return 0;
        }
        $o = $b;
    }
    return strlen($o->s);
}

// ?array.
function readArr(string $p, bool $k): int
{
    $a = [$p . 'a', $p . 'b'];
    if ($k) {
        $a = strlen($p) > 1000000 ? null : [$p . 'c'];
    }
    return $a === null ? 0 : count($a) + strlen($a[0]);
}

// A loop instead of an if: the loop re-kinds the local, so it is a cell for
// the whole function and every overwrite must drop the previous string.
function loopRekind(string $p): int
{
    $x = 0;
    for ($i = 0; $i < 4; $i++) {
        $x = $p . $i;
    }
    return strlen((string)$x);
}

// A loop re-kinding the local with an ALIAS of another string local.
function loopAlias(string $p): int
{
    $x = 0;
    $y = '';
    for ($i = 0; $i < 3; $i++) {
        $y = $p . $i;
        $x = $y;
    }
    return strlen((string)$x) + strlen($y);
}


// A local a reference can reach is never a MIXED slot: a write through the
// alias would not keep its representation flag. `$r = &$d`, `use (&$d)` and a
// by-ref argument, each merged with a null-ternary like the repro.
function app(string &$s): void { $s .= '!'; }

function refa(string $p, bool $c, bool $n): string
{
    $d = '';
    $d .= $p;
    $r = &$d;
    if ($c) {
        $x = $n ? null : dec($d);
        if ($x === null) { return ''; }
        $d = $x;
    }
    $r = $p . 'r';
    return $d;
}

function useref(string $p, bool $c, bool $n): string
{
    $d = '';
    $d .= $p;
    $f = function () use (&$d, $p): void { $d = $p . 'u'; };
    if ($c) {
        $x = $n ? null : dec($d);
        if ($x === null) { return ''; }
        $d = $x;
    }
    $f();
    return $d;
}

function byrefarg(string $p, bool $c, bool $n): string
{
    $d = '';
    $d .= $p;
    if ($c) {
        $x = $n ? null : dec($d);
        if ($x === null) { return ''; }
        $d = $x;
    }
    app($d);
    return $d;
}
$base = str_repeat('x', 4400);
measure('append', fn (int $i): int => strlen(readAppend($base . $i, false, false)));
measure('append taken', fn (int $i): int => strlen(readAppend($base . $i, true, false)));
measure('append null', fn (int $i): int => strlen(readAppend($base . $i, true, true)));
measure('assign', fn (int $i): int => strlen(readAssign($base . $i, $i % 2 === 0)));
measure('fall-through', fn (int $i): int => lenFall($base . $i, $i % 2 === 0));
measure('int|string', fn (int $i): int => intOrString($base . $i, $i % 3 === 0));
measure('?obj', fn (int $i): int => readObj($base . $i, $i % 2 === 0));
measure('?array', fn (int $i): int => readArr($base . $i, $i % 2 === 0));
measure('loop', fn (int $i): int => loopRekind($base . $i));
measure('loop alias', fn (int $i): int => loopAlias($base . $i));
echo readAppend('abc', true, false), ' ', readAppend('abc', false, false), ' [', readAppend('abc', true, true), "]\n";
echo lenFall('ab', true), ' ', lenFall('ab', false), ' ', intOrString('abc', true), ' ', intOrString('abc', false), "\n";
echo byrefarg('abc', false, false), ' ', byrefarg('abc', true, false), ' [', byrefarg('abc', true, true), "]\n";
echo refa('abc', true, true), ' ', useref('abc', false, false), ' ', useref('abc', true, true), "\n";
