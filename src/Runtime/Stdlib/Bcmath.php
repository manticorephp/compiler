<?php

/**
 * ext/bcmath: arbitrary-precision decimal arithmetic on numeric strings.
 *
 * A number is parsed to [negative, digits, scale]: `digits` is the unsigned
 * integer value of the number scaled by 10^scale, no leading zeros ("0" for zero).
 * Magnitudes are little-endian base-1e9 limbs while computing; every result is
 * truncated (never rounded) to the requested scale, as in Zend.
 * Quadratic schoolbook multiply / long division: fine for money-sized operands.
 */

const __BC_BASE = 1000000000;

/** Current default scale (`bcscale()`); set with `bcscale($n)`. */
function __bcmath_default_scale(?int $set = null): int
{
    static $scale = 0;
    $old = $scale;
    if ($set !== null) {
        $scale = $set;
    }
    return $old;
}

function __bcmath_scale_arg(string $fn, int $argNo, ?int $scale): int
{
    if ($scale === null) {
        return __bcmath_default_scale();
    }
    if ($scale < 0 || $scale > 2147483647) {
        throw new ValueError($fn . '(): Argument #' . $argNo . ' ($scale) must be between 0 and 2147483647');
    }
    return $scale;
}

/**
 * @return array{0: bool, 1: string, 2: int} [negative, digits, scale]
 */
function __bcmath_parse(string $fn, int $argNo, string $argName, string $s): array
{
    $n = \strlen($s);
    $i = 0;
    $neg = false;
    if ($i < $n && ($s[$i] === '-' || $s[$i] === '+')) {
        $neg = $s[$i] === '-';
        $i++;
    }
    $intStart = $i;
    while ($i < $n && $s[$i] >= '0' && $s[$i] <= '9') {
        $i++;
    }
    $int = \substr($s, $intStart, $i - $intStart);
    $frac = '';
    if ($i < $n && $s[$i] === '.') {
        $i++;
        $fracStart = $i;
        while ($i < $n && $s[$i] >= '0' && $s[$i] <= '9') {
            $i++;
        }
        $frac = \substr($s, $fracStart, $i - $fracStart);
    }
    if ($i !== $n) {
        throw new ValueError($fn . '(): Argument #' . $argNo . ' ($' . $argName . ') is not well-formed');
    }
    $d = \ltrim($int . $frac, '0');
    if ($d === '') {
        return [false, '0', \strlen($frac)];
    }
    return [$neg, $d, \strlen($frac)];
}

/** @return int[] little-endian limbs */
function __bcmath_limbs(string $d): array
{
    $out = [];
    $i = \strlen($d);
    while ($i > 0) {
        $from = $i - 9;
        if ($from < 0) {
            $from = 0;
        }
        $out[] = (int) \substr($d, $from, $i - $from);
        $i = $from;
    }
    return $out;
}

/** @param int[] $l */
function __bcmath_str(array $l): string
{
    $n = \count($l);
    while ($n > 1 && $l[$n - 1] === 0) {
        $n--;
    }
    $s = (string) $l[$n - 1];
    for ($i = $n - 2; $i >= 0; $i--) {
        $s .= \str_pad((string) $l[$i], 9, '0', STR_PAD_LEFT);
    }
    return $s;
}

/** Compare unsigned digit strings. */
function __bcmath_ucmp(string $a, string $b): int
{
    $la = \strlen($a);
    $lb = \strlen($b);
    if ($la !== $lb) {
        return $la < $lb ? -1 : 1;
    }
    return $a <=> $b;
}

function __bcmath_uadd(string $a, string $b): string
{
    if (\strlen($a) < 18 && \strlen($b) < 18) {
        return (string) ((int) $a + (int) $b);
    }
    $x = __bcmath_limbs($a);
    $y = __bcmath_limbs($b);
    $n = \max(\count($x), \count($y));
    $r = [];
    $carry = 0;
    for ($i = 0; $i < $n; $i++) {
        $v = ($x[$i] ?? 0) + ($y[$i] ?? 0) + $carry;
        if ($v >= __BC_BASE) {
            $v -= __BC_BASE;
            $carry = 1;
        } else {
            $carry = 0;
        }
        $r[] = $v;
    }
    if ($carry > 0) {
        $r[] = $carry;
    }
    return __bcmath_str($r);
}

/** a - b, requires a >= b. */
function __bcmath_usub(string $a, string $b): string
{
    if (\strlen($a) < 19) {
        return (string) ((int) $a - (int) $b);
    }
    $x = __bcmath_limbs($a);
    $y = __bcmath_limbs($b);
    $r = [];
    $borrow = 0;
    $n = \count($x);
    for ($i = 0; $i < $n; $i++) {
        $v = $x[$i] - ($y[$i] ?? 0) - $borrow;
        if ($v < 0) {
            $v += __BC_BASE;
            $borrow = 1;
        } else {
            $borrow = 0;
        }
        $r[] = $v;
    }
    return __bcmath_str($r);
}

function __bcmath_umul(string $a, string $b): string
{
    if ($a === '0' || $b === '0') {
        return '0';
    }
    if (\strlen($a) < 10 && \strlen($b) < 10) {
        return (string) ((int) $a * (int) $b);
    }
    $x = __bcmath_limbs($a);
    $y = __bcmath_limbs($b);
    $nx = \count($x);
    $ny = \count($y);
    $r = \array_fill(0, $nx + $ny, 0);
    for ($i = 0; $i < $nx; $i++) {
        $carry = 0;
        $xi = $x[$i];
        for ($j = 0; $j < $ny; $j++) {
            $cur = $r[$i + $j] + $xi * $y[$j] + $carry;
            $carry = \intdiv($cur, __BC_BASE);
            $r[$i + $j] = $cur - $carry * __BC_BASE;
        }
        $r[$i + $ny] += $carry;
    }
    return __bcmath_str($r);
}

/**
 * Unsigned long division (Knuth D on base-1e9 limbs).
 * @return array{0: string, 1: string} [quotient, remainder]
 */
function __bcmath_udivmod(string $a, string $b): array
{
    if (__bcmath_ucmp($a, $b) < 0) {
        return ['0', $a];
    }
    if (\strlen($a) < 19) {
        $x = (int) $a;
        $y = (int) $b;
        $q = \intdiv($x, $y);
        return [(string) $q, (string) ($x - $q * $y)];
    }
    $v = __bcmath_limbs($b);
    $n = \count($v);
    $u = __bcmath_limbs($a);
    if ($n === 1) {
        $d = $v[0];
        $q = \array_fill(0, \count($u), 0);
        $r = 0;
        for ($i = \count($u) - 1; $i >= 0; $i--) {
            $cur = $r * __BC_BASE + $u[$i];
            $qi = \intdiv($cur, $d);
            $q[$i] = $qi;
            $r = $cur - $qi * $d;
        }
        return [__bcmath_str($q), (string) $r];
    }
    // Knuth algorithm D, base 1e9.
    $norm = \intdiv(__BC_BASE, $v[$n - 1] + 1);
    $v = __bcmath_limbs_mul($v, $norm);
    $u = __bcmath_limbs_mul($u, $norm);
    $m = \count(__bcmath_limbs($a)) - $n;
    while (\count($u) < $m + $n + 1) {
        $u[] = 0;
    }
    $q = \array_fill(0, $m + 1, 0);
    for ($j = $m; $j >= 0; $j--) {
        $top = $u[$j + $n] * __BC_BASE + $u[$j + $n - 1];
        $qhat = \intdiv($top, $v[$n - 1]);
        $rhat = $top - $qhat * $v[$n - 1];
        while ($qhat >= __BC_BASE || ($rhat < __BC_BASE && $qhat * $v[$n - 2] > $rhat * __BC_BASE + $u[$j + $n - 2])) {
            $qhat--;
            $rhat += $v[$n - 1];
            if ($rhat >= __BC_BASE) {
                break;
            }
        }
        $borrow = 0;
        $carry = 0;
        for ($i = 0; $i < $n; $i++) {
            $p = $qhat * $v[$i] + $carry;
            $carry = \intdiv($p, __BC_BASE);
            $t = $u[$i + $j] - ($p - $carry * __BC_BASE) - $borrow;
            if ($t < 0) {
                $t += __BC_BASE;
                $borrow = 1;
            } else {
                $borrow = 0;
            }
            $u[$i + $j] = $t;
        }
        $t = $u[$j + $n] - $carry - $borrow;
        if ($t < 0) {
            $u[$j + $n] = $t + __BC_BASE;
            $qhat--;
            $carry = 0;
            for ($i = 0; $i < $n; $i++) {
                $t = $u[$i + $j] + $v[$i] + $carry;
                if ($t >= __BC_BASE) {
                    $t -= __BC_BASE;
                    $carry = 1;
                } else {
                    $carry = 0;
                }
                $u[$i + $j] = $t;
            }
            $u[$j + $n] = ($u[$j + $n] + $carry) % __BC_BASE;
        } else {
            $u[$j + $n] = $t;
        }
        $q[$j] = $qhat;
    }
    $rem = \array_fill(0, $n, 0);
    $r = 0;
    for ($i = $n - 1; $i >= 0; $i--) {
        $cur = $r * __BC_BASE + $u[$i];
        $qi = \intdiv($cur, $norm);
        $rem[$i] = $qi;
        $r = $cur - $qi * $norm;
    }
    return [__bcmath_str($q), __bcmath_str($rem)];
}

/**
 * @param int[] $l
 * @return int[]
 */
function __bcmath_limbs_mul(array $l, int $k): array
{
    $out = [];
    $carry = 0;
    foreach ($l as $x) {
        $p = $x * $k + $carry;
        $carry = \intdiv($p, __BC_BASE);
        $out[] = $p - $carry * __BC_BASE;
    }
    if ($carry > 0) {
        $out[] = $carry;
    }
    return $out;
}

/** Digits of a number rescaled from scale $from to $to (truncating when $to < $from). */
function __bcmath_rescale(string $d, int $from, int $to): string
{
    if ($to > $from) {
        return $d === '0' ? '0' : $d . \str_repeat('0', $to - $from);
    }
    if ($to < $from) {
        $drop = $from - $to;
        if ($drop >= \strlen($d)) {
            return '0';
        }
        return \substr($d, 0, \strlen($d) - $drop);
    }
    return $d;
}

/** Render [neg, digits, scale] as a string with exactly $scale fraction digits (truncating). */
function __bcmath_fmt(bool $neg, string $d, int $from, int $scale): string
{
    $d = __bcmath_rescale($d, $from, $scale);
    if ($d === '0') {
        $neg = false;
    }
    if ($scale > 0) {
        if (\strlen($d) <= $scale) {
            $d = \str_pad($d, $scale + 1, '0', STR_PAD_LEFT);
        }
        $d = \substr($d, 0, \strlen($d) - $scale) . '.' . \substr($d, \strlen($d) - $scale);
    }
    return $neg ? '-' . $d : $d;
}

/**
 * Signed add of two parsed numbers at common scale.
 * @param array{0: bool, 1: string, 2: int} $a
 * @param array{0: bool, 1: string, 2: int} $b
 * @return array{0: bool, 1: string, 2: int}
 */
function __bcmath_sadd(array $a, array $b, bool $subtract): array
{
    $s = \max($a[2], $b[2]);
    $x = __bcmath_rescale($a[1], $a[2], $s);
    $y = __bcmath_rescale($b[1], $b[2], $s);
    $bn = $subtract ? !$b[0] : $b[0];
    if ($b[1] === '0') {
        $bn = false;
    }
    if ($a[0] === $bn) {
        return [$a[0], __bcmath_uadd($x, $y), $s];
    }
    $c = __bcmath_ucmp($x, $y);
    if ($c === 0) {
        return [false, '0', $s];
    }
    if ($c > 0) {
        return [$a[0], __bcmath_usub($x, $y), $s];
    }
    return [$bn, __bcmath_usub($y, $x), $s];
}

/**
 * a / b truncated to $scale. Caller checks b != 0.
 * @param array{0: bool, 1: string, 2: int} $a
 * @param array{0: bool, 1: string, 2: int} $b
 * @return array{0: bool, 1: string, 2: int}
 */
function __bcmath_sdiv(array $a, array $b, int $scale): array
{
    // (a.d / 10^a.s) / (b.d / 10^b.s) * 10^scale = a.d * 10^(scale + b.s - a.s) / b.d
    $shift = $scale + $b[2] - $a[2];
    $num = $a[1];
    $den = $b[1];
    if ($shift >= 0) {
        $num = $num === '0' ? '0' : $num . \str_repeat('0', $shift);
    } else {
        $den = $den . \str_repeat('0', -$shift);
    }
    [$q] = __bcmath_udivmod($num, $den);
    return [$q === '0' ? false : $a[0] !== $b[0], $q, $scale];
}

/**
 * a mod b: a - b * trunc(a / b), sign of the dividend, at $scale. Caller checks b != 0.
 * @param array{0: bool, 1: string, 2: int} $a
 * @param array{0: bool, 1: string, 2: int} $b
 * @return array{0: bool, 1: string, 2: int}
 */
function __bcmath_smod(array $a, array $b, int $scale): array
{
    $s = \max($a[2], $b[2], $scale);
    $x = __bcmath_rescale($a[1], $a[2], $s);
    $y = __bcmath_rescale($b[1], $b[2], $s);
    [, $r] = __bcmath_udivmod($x, $y);
    return [$r === '0' ? false : $a[0], $r, $s];
}

function bcadd(string $num1, string $num2, ?int $scale = null): string
{
    $sc = __bcmath_scale_arg('bcadd', 3, $scale);
    $a = __bcmath_parse('bcadd', 1, 'num1', $num1);
    $b = __bcmath_parse('bcadd', 2, 'num2', $num2);
    $r = __bcmath_sadd($a, $b, false);
    return __bcmath_fmt($r[0], $r[1], $r[2], $sc);
}

function bcsub(string $num1, string $num2, ?int $scale = null): string
{
    $sc = __bcmath_scale_arg('bcsub', 3, $scale);
    $a = __bcmath_parse('bcsub', 1, 'num1', $num1);
    $b = __bcmath_parse('bcsub', 2, 'num2', $num2);
    $r = __bcmath_sadd($a, $b, true);
    return __bcmath_fmt($r[0], $r[1], $r[2], $sc);
}

function bcmul(string $num1, string $num2, ?int $scale = null): string
{
    $sc = __bcmath_scale_arg('bcmul', 3, $scale);
    $a = __bcmath_parse('bcmul', 1, 'num1', $num1);
    $b = __bcmath_parse('bcmul', 2, 'num2', $num2);
    $d = __bcmath_umul($a[1], $b[1]);
    return __bcmath_fmt($a[0] !== $b[0], $d, $a[2] + $b[2], $sc);
}

function bcdiv(string $num1, string $num2, ?int $scale = null): string
{
    $sc = __bcmath_scale_arg('bcdiv', 3, $scale);
    $a = __bcmath_parse('bcdiv', 1, 'num1', $num1);
    $b = __bcmath_parse('bcdiv', 2, 'num2', $num2);
    if ($b[1] === '0') {
        throw new DivisionByZeroError('Division by zero');
    }
    $r = __bcmath_sdiv($a, $b, $sc);
    return __bcmath_fmt($r[0], $r[1], $r[2], $sc);
}

function bcmod(string $num1, string $num2, ?int $scale = null): string
{
    $sc = __bcmath_scale_arg('bcmod', 3, $scale);
    $a = __bcmath_parse('bcmod', 1, 'num1', $num1);
    $b = __bcmath_parse('bcmod', 2, 'num2', $num2);
    if ($b[1] === '0') {
        throw new DivisionByZeroError('Modulo by zero');
    }
    $r = __bcmath_smod($a, $b, $sc);
    return __bcmath_fmt($r[0], $r[1], $r[2], $sc);
}

/** @return string[] [quotient (scale 0), remainder] */
function bcdivmod(string $num1, string $num2, ?int $scale = null): array
{
    $sc = __bcmath_scale_arg('bcdivmod', 3, $scale);
    $a = __bcmath_parse('bcdivmod', 1, 'num1', $num1);
    $b = __bcmath_parse('bcdivmod', 2, 'num2', $num2);
    if ($b[1] === '0') {
        throw new DivisionByZeroError('Division by zero');
    }
    $q = __bcmath_sdiv($a, $b, 0);
    $r = __bcmath_smod($a, $b, $sc);
    return [__bcmath_fmt($q[0], $q[1], 0, 0), __bcmath_fmt($r[0], $r[1], $r[2], $sc)];
}

function bccomp(string $num1, string $num2, ?int $scale = null): int
{
    $sc = __bcmath_scale_arg('bccomp', 3, $scale);
    $a = __bcmath_parse('bccomp', 1, 'num1', $num1);
    $b = __bcmath_parse('bccomp', 2, 'num2', $num2);
    $x = __bcmath_rescale($a[1], $a[2], \min($a[2], $sc));
    $y = __bcmath_rescale($b[1], $b[2], \min($b[2], $sc));
    $an = $a[0] && $x !== '0';
    $bn = $b[0] && $y !== '0';
    if ($an !== $bn) {
        return $an ? -1 : 1;
    }
    $s = \max(\min($a[2], $sc), \min($b[2], $sc));
    $c = __bcmath_ucmp(__bcmath_rescale($x, \min($a[2], $sc), $s), __bcmath_rescale($y, \min($b[2], $sc), $s));
    return $an ? -$c : $c;
}

function bcscale(?int $scale = null): int
{
    if ($scale !== null && ($scale < 0 || $scale > 2147483647)) {
        throw new ValueError('bcscale(): Argument #1 ($scale) must be between 0 and 2147483647');
    }
    return __bcmath_default_scale($scale);
}

/**
 * Exact integer power of a parsed number; exponent is a non-negative int.
 * @param array{0: bool, 1: string, 2: int} $a
 * @return array{0: bool, 1: string, 2: int}
 */
function __bcmath_spow(array $a, int $e): array
{
    $result = [false, '1', 0];
    $base = $a;
    while ($e > 0) {
        if (($e & 1) === 1) {
            $result = [$result[0] !== $base[0], __bcmath_umul($result[1], $base[1]), $result[2] + $base[2]];
        }
        $e >>= 1;
        if ($e > 0) {
            $base = [false, __bcmath_umul($base[1], $base[1]), $base[2] * 2];
        }
    }
    if ($result[1] === '0') {
        $result[0] = false;
    }
    return $result;
}

/** True when the fraction digits of a parsed number are all zero. */
function __bcmath_is_int(array $p): bool
{
    return $p[2] === 0 || \ltrim(\substr(\str_pad($p[1], $p[2], '0', STR_PAD_LEFT), -$p[2]), '0') === '';
}

/** Integer-valued argument to int; $argNo/$argName for the error. */
function __bcmath_int_arg(string $fn, int $argNo, string $argName, string $s): int
{
    $p = __bcmath_parse($fn, $argNo, $argName, $s);
    if (!__bcmath_is_int($p)) {
        throw new ValueError($fn . '(): Argument #' . $argNo . ' ($' . $argName . ') cannot have a fractional part');
    }
    $d = __bcmath_rescale($p[1], $p[2], 0);
    if (\strlen($d) > 18) {
        throw new ValueError($fn . '(): Argument #' . $argNo . ' ($' . $argName . ') is too large');
    }
    $v = (int) $d;
    return $p[0] ? -$v : $v;
}

function bcpow(string $num, string $exponent, ?int $scale = null): string
{
    $sc = __bcmath_scale_arg('bcpow', 3, $scale);
    $a = __bcmath_parse('bcpow', 1, 'num', $num);
    $e = __bcmath_int_arg('bcpow', 2, 'exponent', $exponent);
    if ($e === 0) {
        return __bcmath_fmt(false, '1', 0, $sc);
    }
    if ($a[1] === '0') {
        if ($e < 0) {
            throw new DivisionByZeroError('Negative power of zero');
        }
        return __bcmath_fmt(false, '0', 0, $sc);
    }
    $p = __bcmath_spow($a, $e < 0 ? -$e : $e);
    if ($e > 0) {
        return __bcmath_fmt($p[0], $p[1], $p[2], $sc);
    }
    $r = __bcmath_sdiv([false, '1', 0], $p, $sc);
    return __bcmath_fmt($r[0], $r[1], $r[2], $sc);
}

function bcpowmod(string $num, string $exponent, string $modulus, ?int $scale = null): string
{
    $sc = __bcmath_scale_arg('bcpowmod', 4, $scale);
    $a = __bcmath_parse('bcpowmod', 1, 'num', $num);
    $e = __bcmath_parse('bcpowmod', 2, 'exponent', $exponent);
    $m = __bcmath_parse('bcpowmod', 3, 'modulus', $modulus);
    foreach ([[$a, 1, 'num'], [$e, 2, 'exponent'], [$m, 3, 'modulus']] as [$x, $no, $nm]) {
        if (!__bcmath_is_int($x)) {
            throw new ValueError('bcpowmod(): Argument #' . $no . ' ($' . $nm . ') cannot have a fractional part');
        }
    }
    if ($e[0] && $e[1] !== '0') {
        throw new ValueError('bcpowmod(): Argument #2 ($exponent) must be greater than or equal to 0');
    }
    if ($m[1] === '0') {
        throw new DivisionByZeroError('Modulo by zero');
    }
    $mi = [false, __bcmath_rescale($m[1], $m[2], 0), 0];
    $base = __bcmath_smod([$a[0], __bcmath_rescale($a[1], $a[2], 0), 0], $mi, 0);
    $exp = __bcmath_rescale($e[1], $e[2], 0);
    $result = [false, '1', 0];
    $result = __bcmath_smod($result, $mi, 0);
    while ($exp !== '0') {
        [$exp, $bit] = __bcmath_udivmod($exp, '2');
        if ($bit === '1') {
            $result = __bcmath_smod([$result[0] !== $base[0], __bcmath_umul($result[1], $base[1]), 0], $mi, 0);
        }
        if ($exp !== '0') {
            $base = __bcmath_smod([false, __bcmath_umul($base[1], $base[1]), 0], $mi, 0);
        }
    }
    return __bcmath_fmt($result[0], $result[1], 0, $sc);
}

function bcsqrt(string $num, ?int $scale = null): string
{
    $sc = __bcmath_scale_arg('bcsqrt', 2, $scale);
    $a = __bcmath_parse('bcsqrt', 1, 'num', $num);
    if ($a[0]) {
        throw new ValueError('bcsqrt(): Argument #1 ($num) must be greater than or equal to 0');
    }
    $rs = \max($sc, $a[2]);
    // floor(sqrt(a.d * 10^(2*rs - a.s))) has scale rs.
    $n = __bcmath_rescale($a[1], $a[2], 2 * $rs);
    if ($n === '0') {
        return __bcmath_fmt(false, '0', 0, $sc);
    }
    // Newton from an overestimate: 10^ceil(len/2) >= sqrt(n).
    $x = '1' . \str_repeat('0', \intdiv(\strlen($n) + 1, 2));
    while (true) {
        [$q] = __bcmath_udivmod($n, $x);
        [$y] = __bcmath_udivmod(__bcmath_uadd($x, $q), '2');
        if (__bcmath_ucmp($y, $x) >= 0) {
            break;
        }
        $x = $y;
    }
    return __bcmath_fmt(false, $x, $rs, $sc);
}

function bcfloor(string $num): string
{
    $a = __bcmath_parse('bcfloor', 1, 'num', $num);
    $q = __bcmath_rescale($a[1], $a[2], 0);
    if ($a[0] && __bcmath_rescale($q, 0, $a[2]) !== $a[1]) {
        $q = __bcmath_uadd($q, '1');
    }
    return __bcmath_fmt($a[0], $q, 0, 0);
}

function bcceil(string $num): string
{
    $a = __bcmath_parse('bcceil', 1, 'num', $num);
    $q = __bcmath_rescale($a[1], $a[2], 0);
    if (!$a[0] && __bcmath_rescale($q, 0, $a[2]) !== $a[1]) {
        $q = __bcmath_uadd($q, '1');
    }
    return __bcmath_fmt($a[0], $q, 0, 0);
}

/**
 * @param int $mode PHP_ROUND_HALF_UP (default, away from zero) / _DOWN / _EVEN / _ODD
 */
function bcround(string $num, int $precision = 0, int $mode = 1): string
{
    $a = __bcmath_parse('bcround', 1, 'num', $num);
    $keep = $precision < 0 ? 0 : $precision;
    $drop = $a[2] - $precision;
    if ($drop <= 0) {
        return __bcmath_fmt($a[0], $a[1], $a[2], $keep);
    }
    $kept = $drop >= \strlen($a[1]) ? '0' : \substr($a[1], 0, \strlen($a[1]) - $drop);
    $rest = $drop >= \strlen($a[1]) ? \str_pad($a[1], $drop, '0', STR_PAD_LEFT) : \substr($a[1], \strlen($a[1]) - $drop);
    // compare the dropped part with one half: 5 followed by zeros
    $half = '5' . \str_repeat('0', $drop - 1);
    $c = $rest === '0' || \ltrim($rest, '0') === '' ? -2 : $rest <=> $half;
    $up = false;
    if ($c > 0) {
        $up = true;
    } elseif ($c === 0) {
        $last = (int) $kept[\strlen($kept) - 1];
        $up = $mode === 1 || ($mode === 3 && $last % 2 === 1) || ($mode === 4 && $last % 2 === 0);
    }
    if ($up) {
        $kept = __bcmath_uadd($kept, '1');
    }
    if ($precision < 0) {
        $kept = $kept === '0' ? '0' : $kept . \str_repeat('0', -$precision);
        return __bcmath_fmt($a[0], $kept, 0, 0);
    }
    return __bcmath_fmt($a[0], $kept, $precision, $keep);
}
