<?php

// A float value returned from an `: int` function must be CONVERTED, not
// reinterpreted. The ABI carries every return through an i64, and a float rides
// that carrier as its raw bits, so a missing fptosi handed the caller the
// double's bit pattern: f() below returned 4627730092099895296.
//
// Only exact conversions here -- a lossy one makes the php interpreter print a
// deprecation to STDOUT, which would poison the expected file.

function f_ret_lit(): int
{
    return 25.0;
}

function f_ret_local(): int
{
    $y = 7.0;
    return $y;
}

function f_ret_param(float $x): int
{
    return $x;
}

function f_ret_expr(float $a, float $b): int
{
    return $a * $b;
}

echo f_ret_lit(), "\n";
echo f_ret_local(), "\n";
echo f_ret_param(25.0), "\n";
echo f_ret_param(-3.0), "\n";
echo f_ret_expr(4.0, 8.0), "\n";
echo f_ret_lit() + 5, "\n";
echo \intdiv(f_ret_lit(), 5), "\n";
