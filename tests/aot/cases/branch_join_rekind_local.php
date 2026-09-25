<?php
// A local whose representation one control-flow path changes (a raw vec / int
// boxed to a cell at an if/else join, a loop back-edge, a `continue`) must be
// read with ONE representation on every path — and a cell used as a STRING
// OFFSET must leave its box before it indexes bytes.
//
// Expected output is php's EXCEPT where php WARNS and carries on — Manticore throws that
// text as a TypeError (where Zend warns, Manticore throws), so these are hand-written:
// offsetRules rows "1x", "0x1", "1e" (`Illegal string offset`), the r/w columns of the
// null, true, false, 1.7, 2.0 rows (`String offset cast occurred`), and the "1x" row of
// nestedProbe (php warns on the inner fetch of an isset / empty / `??` chain).

function tables(): array
{
    static $c = null;
    if ($c !== null) { return $c; }
    $c = [[[1, 2], [3]], [[4], [5]]];
    return $c;
}

function first(array $h): int
{
    $x = $h[0];
    return \count($x);
}

final class K
{
    /** @var array<int,int> */
    public array $a = [7, 8, 9];
}

// repro 1: vec re-kinded to cell on the `continue` path, read as vec on the next iteration
function vecContinue(K $k, int $type): int
{
    $lh = [$k->a, $k->a];
    $phase = 1;
    while (true) {
        if ($phase === 1) {
            if ($type === 1) {
                $t = tables();
                $lh = $t[0];
            } else {
                return -1;
            }
            $phase = 3;
            continue;
        }
        return \first($lh);
    }
}

// repro 2: a cell string-offset key (`$olen + $st[1]`) on the other loop path
function intOffset(string $in): string
{
    $st = [$in, 0];
    $out = \str_repeat('.', 8);
    $olen = 0;
    $phase = 3;
    while (true) {
        if ($phase === 2) {
            $olen = $olen + $st[1];
            break;
        }
        $out[$olen] = 'H';
        $olen = $olen + 1;
        $out[$olen] = 'i';
        $olen = $olen + 1;
        $phase = 2;
    }
    return \substr($out, 0, $olen);
}

// a cell offset with no loop at all: write, read, isset
function cellOffset(array $a): string
{
    $out = \str_repeat('.', 4);
    $i = $a[1];
    $out[$i] = 'H';
    $r = $out[$i] . (isset($out[$i]) ? 'y' : 'n') . (isset($out[$i + 9]) ? 'y' : 'n');
    return $out . ' ' . $r;
}

// the same key local used as a string offset AND an array key, re-kinded by the loop
function sharedKey(array $src): string
{
    $s = 'abcdef';
    $arr = [10, 20, 30, 40, 50, 60];
    $k = 0;
    $acc = '';
    for ($n = 0; $n < 3; $n++) {
        $acc .= $s[$k] . $arr[$k] . ',';
        $s[$k] = 'Z';
        $k = $k + $src[0];
    }
    return $acc . $s;
}

// if/else join inside a `for`: vec on one arm, cell on the other
function forJoin(array $m, int $n): int
{
    $cur = [1, 2, 3];
    $s = 0;
    for ($i = 0; $i < $n; $i++) {
        if ($i % 2 === 0) {
            $s += \count($cur);
        } else {
            $cur = $m['x'];
        }
    }
    return $s;
}

// the same across a `foreach`
function foreachJoin(array $m): int
{
    $cur = [1];
    $s = 0;
    foreach ([0, 1, 2, 3] as $i) {
        $s += \count($cur);
        if ($i === 1) {
            $cur = $m['x'];
        }
    }
    return $s;
}

// do-while back-edge
function doJoin(array $m): int
{
    $cur = [1, 2];
    $s = 0;
    $i = 0;
    do {
        $s += \count($cur);
        if ($i === 0) {
            $cur = $m['x'];
        }
        $i++;
    } while ($i < 3);
    return $s;
}

// `switch` arms: one leaves a vec, the next a cell
function switchJoin(array $m): int
{
    $cur = [1, 2];
    $s = 0;
    for ($i = 0; $i < 4; $i++) {
        switch ($i % 2) {
            case 0:
                $s += \count($cur);
                break;
            default:
                $cur = $m['x'];
        }
    }
    return $s;
}

// `match` picks the new value
function matchJoin(array $m): int
{
    $cur = [1, 2];
    $s = 0;
    for ($i = 0; $i < 3; $i++) {
        $s += \count($cur);
        $cur = match ($i) {
            0 => $m['x'],
            default => [1, 2, 3, 4],
        };
    }
    return $s;
}

// try/catch join
function tryJoin(array $m): int
{
    $cur = [1, 2];
    $s = 0;
    for ($i = 0; $i < 3; $i++) {
        $s += \count($cur);
        try {
            if ($i === 1) { throw new \RuntimeException('x'); }
            $cur = $m['x'];
        } catch (\RuntimeException $e) {
            $cur = [1, 2, 3, 4, 5];
        }
    }
    return $s;
}

// `break` out of the loop with the re-kinded value
function breakJoin(array $m): int
{
    $cur = [1, 2];
    $s = 0;
    $i = 0;
    while (true) {
        $s += \count($cur);
        if ($i === 1) {
            $cur = $m['x'];
            break;
        }
        $i++;
    }
    return $s * 10 + \count($cur);
}

// an int local re-kinded to a cell only on the `continue` path
function intContinue(array $st): int
{
    $x = 1;
    $s = 0;
    for ($i = 0; $i < 4; $i++) {
        if ($i === 1) {
            $x = $x + $st[0];
            continue;
        }
        $s += $x;
    }
    return $s;
}

function thr(int $k): void
{
    if ($k === 1) { throw new \RuntimeException('t'); }
}

// a re-kind in the MIDDLE of the try, undone before its end, seen by the catch
function tryMidRekind(array $m, int $k): int
{
    $cur = [1, 2];
    try {
        $cur = $m['x'];
        thr($k);
        $cur = [1, 2, 3];
    } catch (\RuntimeException $e) {
        return 100 + \count($cur);
    }
    return \count($cur);
}

// an assignment inside a `match` arm: vec on one arm, cell on the other
function matchAssignVec(array $m): int
{
    $cur = [1, 2];
    $s = 0;
    for ($i = 0; $i < 4; $i++) {
        $s += \count($cur);
        match ($i % 2) {
            0 => $cur = $m['x'],
            default => $cur = [1, 2, 3],
        };
    }
    return $s;
}

// the same with an int local and a cell arm
function matchAssignInt(array $st): int
{
    $x = 1;
    $s = 0;
    for ($i = 0; $i < 4; $i++) {
        $s += $x;
        match ($i % 2) {
            0 => $x = $st[0],
            default => $x = $i,
        };
    }
    return $s;
}

// ternary arms with the same shapes
function ternaryAssignVec(array $m): int
{
    $cur = [1, 2];
    $s = 0;
    for ($i = 0; $i < 4; $i++) {
        $s += \count($cur);
        $i % 2 === 0 ? ($cur = $m['x']) : ($cur = [1, 2, 3]);
    }
    return $s;
}

function ternaryAssignInt(array $st): int
{
    $x = 1;
    $s = 0;
    for ($i = 0; $i < 4; $i++) {
        $s += $x;
        $i % 2 === 0 ? ($x = $st[0]) : ($x = $i);
    }
    return $s;
}

// php's string-offset rules for a key that arrives as a cell
function offsetRules(array $ks): string
{
    $out = '';
    foreach ($ks as $j => $k) {
        $s = 'abcd';
        $line = (\is_object($k) ? \get_class($k) : \json_encode($k)) . ':';
        try { $line .= ' r=' . @$s[$k]; } catch (\TypeError $e) { $line .= ' r!' . \get_class($e) . ' ' . $e->getMessage(); }
        if (!\is_float($k)) {
            $line .= ' i=' . (isset($s[$k]) ? 'y' : 'n');
            $line .= ' e=' . (empty($s[$k]) ? 'y' : 'n');
            try { $line .= ' c=' . ($s[$k] ?? 'd'); } catch (\TypeError $e) { $line .= ' c!' . $e->getMessage(); }
        }
        try { @$s[$k] = 'Z'; $line .= ' w=' . $s; } catch (\TypeError $e) { $line .= ' w!' . \get_class($e) . ' ' . $e->getMessage(); }
        $out .= $line . "\n";
    }
    return $out;
}

// a statically STRING-typed offset
function stringOffset(string $k): string
{
    $s = 'abcd';
    try { return $s[$k] . (isset($s[$k]) ? 'y' : 'n'); } catch (\TypeError $e) { return \get_class($e) . ' ' . $e->getMessage(); }
}

// the INNER fetch of an isset / empty / `??` chain follows `??`'s rules, not isset's
function nestedProbe(array $ks): string
{
    $out = '';
    foreach ($ks as $k) {
        $s = 'abcd';
        $line = \json_encode($k) . ':';
        try { $line .= ' c=' . ($s[$k][0] ?? 'D'); } catch (\TypeError $e) { $line .= ' c!' . $e->getMessage(); }
        try { $line .= ' i=' . (isset($s[$k][0]) ? 'y' : 'n'); } catch (\TypeError $e) { $line .= ' i!' . $e->getMessage(); }
        try { $line .= ' e=' . (empty($s[$k][0]) ? 'y' : 'n'); } catch (\TypeError $e) { $line .= ' e!' . $e->getMessage(); }
        $out .= $line . "\n";
    }
    return $out;
}

// `empty` on a statically STRING-typed offset probes, it does not throw
function stringEmpty(string $k): string
{
    $s = 'abcd';
    return (empty($s[$k]) ? 'y' : 'n') . (isset($s[$k]) ? 'y' : 'n');
}

$m = ['x' => [5, 6, 7, 8, 9, 10, 11]];
echo vecContinue(new K(), 1), "\n";
echo intOffset('abc'), "\n";
echo cellOffset(['x', 1]), "\n";
echo sharedKey([1]), "\n";
echo forJoin($m, 5), "\n";
echo foreachJoin($m), "\n";
echo doJoin($m), "\n";
echo switchJoin($m), "\n";
echo matchJoin($m), "\n";
echo tryJoin($m), "\n";
echo breakJoin($m), "\n";
echo intContinue([10]), "\n";
echo tryMidRekind($m, 1), ' ', tryMidRekind($m, 0), "\n";
echo matchAssignVec($m), "\n";
echo matchAssignInt([10]), "\n";
echo ternaryAssignVec($m), "\n";
echo ternaryAssignInt([10]), "\n";
echo offsetRules(['1', ' 1', '1 ', '01', '-1', '+1', '1x', '0x1', 'x', '', ' ', '1.5', '1.', '.5', '1e2', '1e', '9223372036854775808', null, true, false, 1.7, 2.0, 2, '9', [1], new K(), new \stdClass()]);
echo stringOffset('2'), ' ', stringOffset('x'), "\n";
echo stringEmpty('1'), ' ', stringEmpty('x'), ' ', stringEmpty('1x'), ' ', stringEmpty('9'), ' ', stringEmpty('0'), "\n";
echo nestedProbe(['1x', 'x', '1', '9', null]);
