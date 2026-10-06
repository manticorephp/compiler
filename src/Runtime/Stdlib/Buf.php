<?php

// Bootstrap twins of the __mc_nbuf_* codegen builtins (native fixed-width
// buffers: SplFixedArray, Manticore\Ds\*). The previous compiler generation
// links these; the current one shadows them with inline IR. They are also the
// storage of the manticorephp/ds polyfill under Zend. A handle is an int; the
// twin keeps each block as a PHP list in one registry.
//
// Kinds (MemoryAbi::BUF_KIND_*): 1 I8, 2 I16, 3 I32, 4 I64, 5 U8, 6 U16,
// 7 U32, 8 F32, 9 F64, 10 BIT (stored 0/1), 11 CELL.

function __mc_nbuf_zero(int $kind): mixed
{
    if ($kind === 11) { return null; }
    if ($kind === 8 || $kind === 9) { return 0.0; }
    return 0;
}

/**
 * The registry. Ops: 0 alloc($a kind, $b len) · 1 free · 2 len · 3 resize($a)
 * · 4 get($a) · 5 set($a, $v) · 6 move($a from, $b to, $c count)
 * · 7 insert($a at, $b count) · 8 remove($a at, $b count) · 9 find($v, $a from)
 * · 10 take($a at, $b count) → list · 11 clone · 12 kind.
 */
function __mc_nbuf_op(int $op, int $h, int $a, int $b, int $c, mixed $v): mixed
{
    /** @var array<int, array<int, mixed>> $data */
    static $data = [];
    /** @var array<int, int> $kinds */
    static $kinds = [];
    static $next = 1;

    if ($op === 4) { return $data[$h][$a]; }
    if ($op === 5) { $data[$h][$a] = $v; return null; }
    if ($op === 2) { return count($data[$h]); }
    if ($op === 12) { return $kinds[$h]; }
    if ($op === 0) {
        $n = $next;
        $next = $next + 1;
        $zero = __mc_nbuf_zero($a);
        $block = [];
        for ($k = 0; $k < $b; $k++) { $block[] = $zero; }
        $data[$n] = $block;
        $kinds[$n] = $a;
        return $n;
    }
    if ($op === 1) { unset($data[$h]); unset($kinds[$h]); return null; }
    if ($op === 3) {
        $zero = __mc_nbuf_zero($kinds[$h]);
        $n = count($data[$h]);
        while ($n > $a) { array_pop($data[$h]); $n = $n - 1; }
        while ($n < $a) { $data[$h][] = $zero; $n = $n + 1; }
        return null;
    }
    if ($op === 6) {
        $slice = array_slice($data[$h], $a, $c);
        for ($k = 0; $k < $c; $k++) { $data[$h][$b + $k] = $slice[$k]; }
        return null;
    }
    if ($op === 7) {
        if ($b <= 0) { return null; }
        $zero = __mc_nbuf_zero($kinds[$h]);
        $old = $data[$h];
        $n = count($old);
        $block = [];
        for ($k = 0; $k < $a; $k++) { $block[] = $old[$k]; }
        for ($k = 0; $k < $b; $k++) { $block[] = $zero; }
        for ($k = $a; $k < $n; $k++) { $block[] = $old[$k]; }
        $data[$h] = $block;
        return null;
    }
    if ($op === 8) {
        if ($b <= 0) { return null; }
        $old = $data[$h];
        $n = count($old);
        $block = [];
        for ($k = 0; $k < $a; $k++) { $block[] = $old[$k]; }
        for ($k = $a + $b; $k < $n; $k++) { $block[] = $old[$k]; }
        $data[$h] = $block;
        return null;
    }
    if ($op === 9) {
        $n = count($data[$h]);
        for ($k = $a; $k < $n; $k++) {
            if ($data[$h][$k] === $v) { return $k; }
        }
        return -1;
    }
    if ($op === 10) { return array_slice($data[$h], $a, $b); }
    if ($op === 11) {
        $n = $next;
        $next = $next + 1;
        $data[$n] = $data[$h];
        $kinds[$n] = $kinds[$h];
        return $n;
    }
    return null;
}

function __mc_nbuf_alloc(int $kind, int $len): int { return (int) __mc_nbuf_op(0, 0, $kind, $len, 0, null); }
function __mc_nbuf_free(int $h): void { __mc_nbuf_op(1, $h, 0, 0, 0, null); }
function __mc_nbuf_len(int $h): int { return (int) __mc_nbuf_op(2, $h, 0, 0, 0, null); }
function __mc_nbuf_resize(int $h, int $len): int { __mc_nbuf_op(3, $h, $len, 0, 0, null); return $h; }

function __mc_nbuf_get_i(int $h, int $i): int { return (int) __mc_nbuf_op(4, $h, $i, 0, 0, null); }
function __mc_nbuf_get_f(int $h, int $i): float { return (float) __mc_nbuf_op(4, $h, $i, 0, 0, null); }
function __mc_nbuf_get_c(int $h, int $i): mixed { return __mc_nbuf_op(4, $h, $i, 0, 0, null); }

/** `$v` rounded to the nearest IEEE-754 binary32 value (ties to even), as a double. */
function __mc_nbuf_f32(float $v): float
{
    if ($v === 0.0 || is_nan($v) || is_infinite($v)) { return $v; }
    $a = abs($v);
    if ($a >= 3.4028235677973366e38) { return $v > 0.0 ? INF : -INF; }
    $e = (int) floor(log($a, 2.0));
    if (2.0 ** $e > $a) { $e = $e - 1; } elseif (2.0 ** ($e + 1) <= $a) { $e = $e + 1; }
    if ($e < -126) { $e = -126; }
    $ulp = 2.0 ** ($e - 23);
    $q = $a / $ulp;
    $f = floor($q);
    $d = $q - $f;
    if ($d > 0.5 || ($d === 0.5 && fmod($f, 2.0) === 1.0)) { $f = $f + 1.0; }
    $r = $f * $ulp;
    return $v > 0.0 ? $r : -$r;
}

function __mc_nbuf_set_i(int $h, int $i, int $v): void { __mc_nbuf_op(5, $h, $i, 0, 0, $v); }
function __mc_nbuf_set_f(int $h, int $i, float $v): void
{
    if ((int) __mc_nbuf_op(12, $h, 0, 0, 0, null) === 8) { $v = __mc_nbuf_f32($v); }
    __mc_nbuf_op(5, $h, $i, 0, 0, $v);
}
function __mc_nbuf_set_c(int $h, int $i, mixed $v): void { __mc_nbuf_op(5, $h, $i, 0, 0, $v); }

function __mc_nbuf_move(int $h, int $from, int $to, int $count): void { __mc_nbuf_op(6, $h, $from, $to, $count, null); }
function __mc_nbuf_insert(int $h, int $at, int $count): int { __mc_nbuf_op(7, $h, $at, $count, 0, null); return $h; }
function __mc_nbuf_remove(int $h, int $at, int $count): void { __mc_nbuf_op(8, $h, $at, $count, 0, null); }

function __mc_nbuf_fill_i(int $h, int $v, int $from, int $to): void
{
    for ($k = $from; $k < $to; $k++) { __mc_nbuf_op(5, $h, $k, 0, 0, $v); }
}
function __mc_nbuf_fill_f(int $h, float $v, int $from, int $to): void
{
    for ($k = $from; $k < $to; $k++) { __mc_nbuf_set_f($h, $k, $v); }
}

function __mc_nbuf_find_i(int $h, int $v, int $from): int { return (int) __mc_nbuf_op(9, $h, $from, 0, 0, $v); }
function __mc_nbuf_find_f(int $h, float $v, int $from): int { return (int) __mc_nbuf_op(9, $h, $from, 0, 0, $v); }

function __mc_nbuf_copy(int $dst, int $dstAt, int $src, int $srcAt, int $count): void
{
    /** @var array<int, mixed> $slice */
    $slice = __mc_nbuf_op(10, $src, $srcAt, $count, 0, null);
    for ($k = 0; $k < $count; $k++) { __mc_nbuf_op(5, $dst, $dstAt + $k, 0, 0, $slice[$k]); }
}

/** Sum (`$op` 0), minimum (1) or maximum (2) of an int-kind buffer; the identity when empty. */
function __mc_nbuf_reduce_i(int $h, int $op): int
{
    $n = __mc_nbuf_len($h);
    $a = $op === 0 ? 0 : ($op === 1 ? PHP_INT_MAX : PHP_INT_MIN);
    for ($k = 0; $k < $n; $k++) {
        $v = (int) __mc_nbuf_op(4, $h, $k, 0, 0, null);
        if ($op === 0) { $a = $a + $v; }
        elseif ($op === 1) { if ($v < $a) { $a = $v; } }
        elseif ($v > $a) { $a = $v; }
    }
    return $a;
}

/** The same over a float-kind buffer. */
function __mc_nbuf_reduce_f(int $h, int $op): float
{
    $n = __mc_nbuf_len($h);
    $a = $op === 0 ? 0.0 : ($op === 1 ? INF : -INF);
    for ($k = 0; $k < $n; $k++) {
        $v = (float) __mc_nbuf_op(4, $h, $k, 0, 0, null);
        if ($op === 0) { $a = $a + $v; }
        elseif ($op === 1) { if ($v < $a) { $a = $v; } }
        elseif ($v > $a) { $a = $v; }
    }
    return $a;
}

/** 1 when two buffers of one kind and length hold equal elements. */
function __mc_nbuf_same(int $a, int $b): int
{
    $n = __mc_nbuf_len($a);
    for ($k = 0; $k < $n; $k++) {
        if (__mc_nbuf_op(4, $a, $k, 0, 0, null) != __mc_nbuf_op(4, $b, $k, 0, 0, null)) { return 0; }
    }
    return 1;
}

function __mc_nbuf_clone(int $h): int { return (int) __mc_nbuf_op(11, $h, 0, 0, 0, null); }
