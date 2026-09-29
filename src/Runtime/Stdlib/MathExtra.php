<?php

/**
 * Additional PHP math functions: base conversions (string<->int) and the
 * float-classification predicates. Pure-PHP / global namespace. The trig and
 * libm-backed functions (sin, sqrt, …) are codegen builtins elsewhere; these are
 * the ones expressible directly.
 */

/** Parse a hexadecimal string to an int, ignoring non-hex bytes (PHP `hexdec`).
 *  Overflow past the int range is not promoted to float here. */
function hexdec(string $hex_string): int
{
    $n = \strlen($hex_string);
    $v = 0;
    for ($i = 0; $i < $n; $i = $i + 1) {
        $o = \ord($hex_string[$i]);
        if ($o >= 48 && $o <= 57) { $v = $v * 16 + ($o - 48); }
        elseif ($o >= 97 && $o <= 102) { $v = $v * 16 + ($o - 87); }
        elseif ($o >= 65 && $o <= 70) { $v = $v * 16 + ($o - 55); }
    }
    return $v;
}

/** Parse an octal string to an int (PHP `octdec`). */
function octdec(string $octal_string): int
{
    $n = \strlen($octal_string);
    $v = 0;
    for ($i = 0; $i < $n; $i = $i + 1) {
        $o = \ord($octal_string[$i]);
        if ($o >= 48 && $o <= 55) { $v = $v * 8 + ($o - 48); }
    }
    return $v;
}

/** Parse a binary string to an int (PHP `bindec`). */
function bindec(string $binary_string): int
{
    $n = \strlen($binary_string);
    $v = 0;
    for ($i = 0; $i < $n; $i = $i + 1) {
        $o = \ord($binary_string[$i]);
        if ($o === 48) { $v = $v * 2; }
        elseif ($o === 49) { $v = $v * 2 + 1; }
    }
    return $v;
}

/** An int as a base-2 string (PHP `decbin`). A negative number is its full
 *  64-bit two's-complement form (php treats the value as unsigned), so it has
 *  no `$v > 0` termination — walk all 64 bits and let leading ones stand. */
function decbin(int $num): string
{
    if ($num === 0) { return "0"; }
    if ($num < 0) {
        $out = "";
        for ($i = 0; $i < 64; $i = $i + 1) {
            // `>>` is arithmetic (sign-extending), but `& 1` isolates bit $i, so
            // this reads each of the 64 bits of the two's-complement pattern.
            $out = (($num >> $i) & 1) === 1 ? ("1" . $out) : ("0" . $out);
        }
        return $out;
    }
    $out = "";
    $v = $num;
    while ($v > 0) {
        $out = ($v & 1) === 1 ? ("1" . $out) : ("0" . $out);
        $v = \intdiv($v, 2);
    }
    return $out;
}

/** A non-negative int as a base-8 string (PHP `decoct`). */
function decoct(int $num): string
{
    if ($num === 0) { return "0"; }
    $d = "01234567";
    $out = "";
    $v = $num;
    while ($v > 0) {
        $out = $d[$v & 7] . $out;
        $v = \intdiv($v, 8);
    }
    return $out;
}

/**
 * Convert `$num` (a non-negative integer written in base `$from_base`) to base
 * `$to_base`, bases 2..36, digits 0-9a-z (PHP `base_convert`).
 */
function base_convert(string $num, int $from_base, int $to_base): string
{
    $digits = "0123456789abcdefghijklmnopqrstuvwxyz";
    $n = \strlen($num);
    $val = 0;
    for ($i = 0; $i < $n; $i = $i + 1) {
        $o = \ord(\strtolower($num[$i]));
        $dv = -1;
        if ($o >= 48 && $o <= 57) { $dv = $o - 48; }
        elseif ($o >= 97 && $o <= 122) { $dv = $o - 87; }
        if ($dv >= 0 && $dv < $from_base) { $val = $val * $from_base + $dv; }
    }
    if ($val === 0) { return "0"; }
    $out = "";
    while ($val > 0) {
        $out = $digits[$val % $to_base] . $out;
        $val = \intdiv($val, $to_base);
    }
    return $out;
}

/** True when `$num` is IEEE NaN (exponent all-ones, non-zero mantissa). */
function is_nan(float $num): bool
{
    $bits = \__float_bits($num);
    return (($bits >> 52) & 2047) === 2047 && ($bits & 4503599627370495) !== 0;
}

/** True when `$num` is +/-INF. */
function is_infinite(float $num): bool
{
    $bits = \__float_bits($num);
    return (($bits >> 52) & 2047) === 2047 && ($bits & 4503599627370495) === 0;
}

/** True when `$num` is neither NaN nor infinite. */
function is_finite(float $num): bool
{
    $bits = \__float_bits($num);
    return (($bits >> 52) & 2047) !== 2047;
}

/**
 * The C `double` at a byte offset. A codegen builtin shadows this body; it is what a
 * compiler one generation behind links (the bootstrap rule).
 */
function peek_f64(\Ffi\Ptr $ptr, int $offset): float
{
    return \__mc_f64_from_bits(\peek_i64($ptr, $offset));
}

/** Store a C `double` at a byte offset; the bootstrap body of the codegen builtin. */
function poke_f64(\Ffi\Ptr $ptr, int $offset, float $value): int
{
    \poke_i64($ptr, $offset, \__float_bits($value));
    return 0;
}

/** The double whose IEEE-754 bit pattern is `$bits` (the inverse of __float_bits). */
function __mc_f64_from_bits(int $bits): float
{
    $sign = $bits < 0 ? -1.0 : 1.0;
    $e = ($bits >> 52) & 0x7FF;
    $m = $bits & 0xFFFFFFFFFFFFF;
    if ($e === 0x7FF) {
        return $m === 0 ? $sign * INF : NAN;
    }
    if ($e === 0) {
        return $sign * ($m * 2.0 ** -1074);
    }
    return $sign * (($m + 4503599627370496) * 2.0 ** ($e - 1075));
}

/** IEEE 754 division: a zero divisor answers ±INF or NAN instead of throwing, as `/` does. */
function fdiv(float $num1, float $num2): float
{
    if ($num2 != 0.0) {
        return $num1 / $num2;
    }
    if (\is_nan($num1) || $num1 == 0.0) {
        return NAN;
    }
    $neg = ($num1 < 0.0) !== (\__float_bits($num2) < 0);
    return $neg ? -INF : INF;
}
