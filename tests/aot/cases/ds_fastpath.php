<?php
use Manticore\Ds\Int8Array;
use Manticore\Ds\Int32Array;
use Manticore\Ds\Int64Array;
use Manticore\Ds\UInt8Array;
use Manticore\Ds\UInt16Array;
use Manticore\Ds\UInt32Array;
use Manticore\Ds\Float32Array;
use Manticore\Ds\Float64Array;
use Manticore\Ds\BitArray;

function sum32(int $n): int
{
    $a = new Int32Array($n);
    for ($i = 0; $i < $n; $i++) { $a[$i] = $i * 3 - 1000; }
    $s = 0;
    for ($i = 0; $i < $n; $i++) { $s = $s + $a[$i]; }
    for ($i = 1; $i < $n; $i++) { $a[$i] = $a[$i - 1] + $a[$i]; }
    return $s + $a[$n - 1];
}
function dot(int $n): float
{
    $x = new Float64Array($n); $y = new Float32Array($n);
    for ($i = 0; $i < $n; $i++) { $x[$i] = $i * 0.5; $y[$i] = 0.1; $x[$i] = $x[$i] + $i; }
    $d = 0.0;
    for ($i = 0; $i < $n; $i++) { $d = $d + $x[$i] * $y[$i]; }
    return $d;
}
function popcount(int $n): int
{
    $b = new BitArray($n);
    for ($i = 0; $i < $n; $i++) { $on = $i % 3 === 0; $b[$i] = $on; }
    $b[1] = true; $b[0] = false;
    $c = 0;
    for ($i = 0; $i < $n; $i++) { if ($b[$i]) { $c++; } }
    return $c;
}
function narrow(): string
{
    $s = new Int8Array(3); $u = new UInt8Array(3); $w = new UInt16Array(2); $q = new UInt32Array(2); $l = new Int64Array(2);
    $lo = -128; $hi = 255; $big = 4294967295; $neg = -5;
    $s[0] = $lo; $s[1] = 127; $s[2] = $neg;
    $u[0] = $hi; $u[1] = 0; $u[2] = 128;
    $w[0] = 65535; $w[1] = 40000;
    $q[0] = $big; $q[1] = 3000000000;
    $l[0] = PHP_INT_MIN; $l[1] = PHP_INT_MAX;
    return $s[0] . ' ' . $s[1] . ' ' . $s[2] . ' | ' . $u[0] . ' ' . $u[1] . ' ' . $u[2] . ' | ' . $w[0] . ' ' . $w[1]
        . ' | ' . $q[0] . ' ' . $q[1] . ' | ' . $l[0] . ' ' . $l[1];
}
function errors(): void
{
    $a = new Int8Array(4); $f = new Float64Array(2); $b = new BitArray(2);
    $i = 4; $v = 200; $j = -1;
    try { echo $a[$i], "\n"; } catch (\Throwable $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
    try { echo $a[$j], "\n"; } catch (\Throwable $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
    try { $a[$i] = 1; } catch (\Throwable $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
    try { $a[0] = $v; } catch (\Throwable $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
    try { $v = -129; $a[1] = $v; } catch (\Throwable $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
    try { $f[$i] = 1.5; } catch (\Throwable $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
    try { echo $f[2], "\n"; } catch (\Throwable $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
    try { $t = true; $b[$i] = $t; } catch (\Throwable $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
    try { var_dump($b[2]); } catch (\Throwable $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
    echo $a[0], ' ', $a[1], ' ', $f[0], "\n";
    $n = 2; $f[0] = $n; $f[1] = 7;
    var_dump($f[0], $f[1]);
    $a->setSize(1);
    try { echo $a[1], "\n"; } catch (\Throwable $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
    $a->setSize(600); $k = 599; $a[$k] = 9; echo $a[$k], ' ', count($a), "\n";
}
echo sum32(1000), "\n";
var_dump(dot(100));
echo popcount(200), "\n";
echo narrow(), "\n";
errors();
