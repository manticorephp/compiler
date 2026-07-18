<?php

/**
 * Runtime printf-family format engine. `sprintf`/`printf` with a LITERAL format
 * are inlined by the compiler ({@see EmitLlvmBuiltins::biSprintf}); a RUNTIME
 * (variable) format, and every `v*printf` / `fprintf`, routes here.
 *
 * `__mc_format` parses the PHP spec `%[argnum$][flags][width][.precision]conv`
 * itself — positional args, the `'X` custom pad char, and `%b` (binary) have no
 * C printf equivalent — and drives one `__mc_fmt_int/float/str` codegen builtin
 * (a single-conversion snprintf) per conversion. `%e`/`%g` get PHP's minimum-
 * width exponent via `__mc_fix_exp`. Verified byte-identical to php 8.5 sprintf.
 */

/** Left/right pad $s to $width with a single-byte $pad. */
function __mc_pad(string $s, int $width, string $pad, bool $left): string
{
    $len = \strlen($s);
    if ($len >= $width || $pad === '') {
        return $s;
    }
    $fill = \str_repeat($pad, $width - $len);
    return $left ? ($s . $fill) : ($fill . $s);
}

/**
 * Format $args against the PHP printf spec $fmt.
 * @param array<int,mixed> $args
 */
function __mc_format(string $fmt, array $args): string
{
    $out = '';
    $n = \strlen($fmt);
    $i = 0;
    $seq = 0; // next sequential (non-positional) arg
    while ($i < $n) {
        $ch = $fmt[$i];
        if ($ch !== '%') {
            $out .= $ch;
            $i = $i + 1;
            continue;
        }
        $j = $i + 1;
        if ($j < $n && $fmt[$j] === '%') {
            $out .= '%';
            $i = $j + 1;
            continue;
        }
        // argnum$  (positional): digits then '$'
        $argnum = -1;
        $k = $j;
        $num = '';
        while ($k < $n && $fmt[$k] >= '0' && $fmt[$k] <= '9') {
            $num .= $fmt[$k];
            $k = $k + 1;
        }
        if ($num !== '' && $k < $n && $fmt[$k] === '$') {
            $argnum = \intval($num) - 1;
            $j = $k + 1;
        }
        // flags
        $left = false;
        $plus = false;
        $zero = false;
        $pad = ' ';
        while ($j < $n) {
            $c = $fmt[$j];
            if ($c === '-') { $left = true; $j = $j + 1; }
            elseif ($c === '+') { $plus = true; $j = $j + 1; }
            elseif ($c === ' ') { $j = $j + 1; } // PHP's space is the default pad, not C sign-space
            elseif ($c === '0') { $zero = true; $pad = '0'; $j = $j + 1; }
            elseif ($c === "'" && $j + 1 < $n) { $pad = $fmt[$j + 1]; $j = $j + 2; }
            else { break; }
        }
        // width
        $width = 0;
        while ($j < $n && $fmt[$j] >= '0' && $fmt[$j] <= '9') {
            $width = $width * 10 + (\ord($fmt[$j]) - 48);
            $j = $j + 1;
        }
        // .precision
        $hasPrec = false;
        $prec = '';
        if ($j < $n && $fmt[$j] === '.') {
            $hasPrec = true;
            $j = $j + 1;
            while ($j < $n && $fmt[$j] >= '0' && $fmt[$j] <= '9') {
                $prec .= $fmt[$j];
                $j = $j + 1;
            }
        }
        if ($j >= $n) {
            $out .= \substr($fmt, $i);
            break;
        }
        $conv = $fmt[$j];
        $j = $j + 1;
        $i = $j;
        // pick the arg (positional or the next sequential)
        $ai = ($argnum >= 0) ? $argnum : $seq;
        if ($argnum < 0) { $seq = $seq + 1; }
        $arg = ($ai >= 0 && $ai < \count($args)) ? $args[$ai] : null;

        // A custom pad char (anything but space/zero) has no C printf form —
        // format the core WITHOUT width, then pad by hand. Standard space/zero
        // padding + sign + width goes straight through snprintf.
        $custom = ($pad !== ' ' && $pad !== '0');
        $precPart = $hasPrec ? ('.' . $prec) : '';
        $signPart = $plus ? '+' : '';
        $val = $out; // placeholder, reassigned below
        $piece = '';

        if ($conv === 'b') {
            // binary — no C conversion; width/pad applies to the digit string
            $piece = \decbin((int)$arg);
        } elseif ($conv === 's') {
            $cfmt = $custom ? ('%' . $precPart . 's') : ('%' . ($left ? '-' : '') . $width . $precPart . 's');
            $piece = \__mc_fmt_str($cfmt, $arg);
        } elseif ($conv === 'f' || $conv === 'F') {
            // PHP prints negative zero as `0` (C's snprintf keeps the sign bit) —
            // normalise -0.0 to +0.0 before formatting.
            $fv = (float)$arg;
            if ($fv === 0.0) { $fv = 0.0; }
            $core = $custom
                ? ('%' . $signPart . $precPart . $conv)
                : ('%' . ($left ? '-' : '') . $signPart . ($zero ? '0' : '') . $width . $precPart . $conv);
            $piece = \__mc_fmt_float($core, $fv);
        } elseif ($conv === 'e' || $conv === 'E' || $conv === 'g' || $conv === 'G') {
            // Exponent conversions: snprintf gives C's 2-digit exponent (`e+04`),
            // which __mc_fix_exp shrinks to PHP's minimum (`e+4`). That changes the
            // string length, so width padding MUST come AFTER — format the core
            // WITHOUT width, fix the exponent, then pad by hand (else a C-padded
            // field loses a space when the exponent shrinks).
            $ev = (float)$arg;
            // %e/%E drop a negative zero's sign like %f; %g/%G KEEP it (`-0`).
            if ($ev === 0.0 && ($conv === 'e' || $conv === 'E')) { $ev = 0.0; }
            $core = '%' . $signPart . $precPart . $conv;
            $piece = \__mc_fix_exp(\__mc_fmt_float($core, $ev));
            if ($width > 0) {
                $pc = $custom ? $pad[0] : (($zero && !$left) ? '0' : ' ');
                $piece = \__mc_pad($piece, $width, $pc, $left);
            }
        } elseif ($conv === 'c') {
            $piece = \__mc_fmt_str('%s', \chr((int)$arg));
        } else {
            // integer conversions
            $cc = 'lld';
            if ($conv === 'u') { $cc = 'llu'; }
            elseif ($conv === 'x') { $cc = 'llx'; }
            elseif ($conv === 'X') { $cc = 'llX'; }
            elseif ($conv === 'o') { $cc = 'llo'; }
            elseif ($conv === 'd' || $conv === 'i') { $cc = 'lld'; }
            else {
                // unknown conversion: emit it verbatim, consume no value
                $out .= '%' . $conv;
                if ($argnum < 0) { $seq = $seq - 1; }
                continue;
            }
            $core = $custom
                ? ('%' . $signPart . $precPart . $cc)
                : ('%' . ($left ? '-' : '') . $signPart . ($zero ? '0' : '') . $width . $precPart . $cc);
            $piece = \__mc_fmt_int($core, $arg);
        }
        $isExp = ($conv === 'e' || $conv === 'E' || $conv === 'g' || $conv === 'G');
        if ($custom && $width > 0 && !$isExp) {
            // e/E/g/G already padded above (after the exponent fix)
            $piece = \__mc_pad($piece, $width, $pad[0], $left);
        } elseif (($conv === 'b') && $width > 0) {
            // %b without a custom pad still honours width via the flag pad
            $piece = \__mc_pad($piece, $width, ($pad === '0') ? '0' : ' ', $left);
        }
        $out .= $piece;
    }
    return $out;
}

/**
 * sprintf with the args in an array.
 * @param array<int,mixed> $args
 */
function vsprintf(string $format, #[\Manticore\Attr\CellArg] array $args): string
{
    return \__mc_format($format, $args);
}

/**
 * printf with the args in an array; returns the byte count.
 * @param array<int,mixed> $args
 */
function vprintf(string $format, #[\Manticore\Attr\CellArg] array $args): int
{
    $s = \__mc_format($format, $args);
    echo $s;
    return \strlen($s);
}

/**
 * Write a formatted string to $stream; returns the byte count.
 * @param mixed ...$args
 */
function fprintf(\Resource $stream, string $format, ...$args): int
{
    return \fwrite($stream, \__mc_format($format, $args));
}

/**
 * fprintf with the args in an array.
 * @param array<int,mixed> $args
 */
function vfprintf(\Resource $stream, string $format, #[\Manticore\Attr\CellArg] array $args): int
{
    return \fwrite($stream, \__mc_format($format, $args));
}
