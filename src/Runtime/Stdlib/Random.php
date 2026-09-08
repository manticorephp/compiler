<?php

// Randomness, both of php's generators. random_bytes / random_int are the
// cryptographically-secure pair, on libc getentropy(2) (Runtime\Libc\sys_getentropy),
// the cross-host CSPRNG. Below them sits the legacy Mersenne-Twister engine that
// mt_rand / rand / shuffle draw from — php's MT19937 exactly, seed for seed.

/**
 * $length secure random bytes as a binary string. php throws ValueError for
 * $length < 1. getentropy fills at most 256 bytes per call, so a longer request
 * loops.
 */
function random_bytes(int $length): string
{
    if ($length < 1) {
        throw new \ValueError('random_bytes(): Argument #1 ($length) must be greater than 0');
    }
    $buf = \Runtime\Libc\calloc($length, 1);
    if ($buf === null) {
        throw new \Exception('random_bytes(): cannot allocate');
    }
    $off = 0;
    while ($off < $length) {
        $chunk = $length - $off;
        if ($chunk > 256) { $chunk = 256; }
        if (\Runtime\Libc\sys_getentropy(\ptr_offset($buf, $off), $chunk) !== 0) {
            \Runtime\Libc\free($buf);
            throw new \Exception('random_bytes(): Could not gather sufficient random data');
        }
        $off = $off + $chunk;
    }
    $out = \str_from_buffer($buf, $length);
    \Runtime\Libc\free($buf);
    return $out;
}

/**
 * A uniform secure int in [$min, $max]. php throws ValueError when $min > $max.
 * Unbiased: read just enough random bytes to cover the range, mask to the next
 * power-of-two-minus-one, and reject values above the range (rejection sampling).
 */
function random_int(int $min, int $max): int
{
    if ($min > $max) {
        throw new \ValueError('random_int(): Argument #1 ($min) must be less than or equal to argument #2 ($max)');
    }
    if ($min === $max) {
        return $min;
    }
    $range = $max - $min;   // number of distinct values minus one

    // Smallest all-ones mask >= $range, and the byte count that covers it.
    $bits = 0;
    $tmp = $range;
    while ($tmp > 0) {
        $bits = $bits + 1;
        $tmp = $tmp >> 1;
    }
    $bytes = \intdiv($bits + 7, 8);
    $mask = $bits >= 63 ? 0x7FFFFFFFFFFFFFFF : ((1 << $bits) - 1);

    while (true) {
        $rb = \random_bytes($bytes);
        $val = 0;
        for ($i = 0; $i < $bytes; $i = $i + 1) {
            $val = ($val << 8) | \ord($rb[$i]);
        }
        $val = $val & $mask;
        if ($val <= $range) {
            return $min + $val;
        }
    }
}

// ---------------------------------------------------------------------------
// The legacy Mersenne-Twister engine — mt_rand / rand and everything that draws
// from it. php's MT19937 is the reference generator verbatim, so a seeded
// sequence here is byte-identical to php's: `mt_srand(12345)` then `mt_rand()`
// answers the same numbers in both, which is what makes a shuffle difftest-able
// at all. NOT a CSPRNG — `random_int` above is the one to reach for.
// ---------------------------------------------------------------------------

/**
 * The whole engine in one function, because the state is one `static` slot and
 * a static local is this runtime's module global (the Apcu.php shape). Ops:
 *   `next`    — the next tempered 32-bit word, php's `php_mt_rand()`
 *   `srand`   — re-seed from $arg
 *   `mode`    — 0 = MT_RAND_MT19937 (correct), 1 = MT_RAND_PHP (the historic
 *               broken twist, which takes the low bit of the WRONG word)
 *   `getmode` — read it back
 *
 * $idx 625 means "never seeded": php seeds itself from the OS on first use, and
 * so does this — through `random_bytes`, which is a better entropy source than
 * php's own GENERATE_SEED().
 */
function __mc_mt_op(string $op, int $arg = 0): int
{
    static $st = [];
    static $idx = 625;
    static $mode = 0;

    if ($op === 'mode') { $mode = $arg; return 0; }
    if ($op === 'getmode') { return $mode; }

    $seed = -1;
    if ($op === 'srand') { $seed = $arg & 0xFFFFFFFF; }
    elseif ($idx === 625) { $seed = \random_int(0, 0xFFFFFFFF); }

    if ($seed >= 0) {
        // php_mt_initialize: Knuth's 1812433253 expansion of one 32-bit seed.
        $st = [];
        $st[0] = $seed;
        $prev = $seed;
        for ($i = 1; $i < 624; $i = $i + 1) {
            $prev = (1812433253 * ($prev ^ ($prev >> 30)) + $i) & 0xFFFFFFFF;
            $st[$i] = $prev;
        }
        $idx = 624;
        if ($op === 'srand') { return 0; }
    }

    if ($idx >= 624) {
        // php_mt_reload, in place. The modular reads are exact: below 227 the
        // (i+397) neighbour is still the OLD word and above it the NEW one,
        // and at i = 623 the (i+1) neighbour is the NEW state[0] — which is
        // what the reference algorithm's tail step does by hand.
        for ($i = 0; $i < 624; $i = $i + 1) {
            $u = $st[$i];
            $v = $st[($i + 1) % 624];
            $y = ($u & 0x80000000) | ($v & 0x7FFFFFFF);
            $n = $st[($i + 397) % 624] ^ ($y >> 1);
            $lo = $mode === 0 ? ($v & 1) : ($u & 1);
            if ($lo !== 0) { $n = $n ^ 0x9908B0DF; }
            $st[$i] = $n;
        }
        $idx = 0;
    }

    $y = $st[$idx];
    $idx = $idx + 1;
    $y = $y ^ ($y >> 11);
    $y = $y ^ (($y << 7) & 0x9D2C5680);
    $y = $y ^ (($y << 15) & 0xEFC60000);

    return ($y ^ ($y >> 18)) & 0xFFFFFFFF;
}

/** Unsigned 64-bit `<`, for the words the wide range path treats as unsigned. */
function __mc_u64_lt(int $a, int $b): bool
{
    return ($a ^ \PHP_INT_MIN) < ($b ^ \PHP_INT_MIN);
}

/**
 * Unsigned 64-bit `%`. Halving first keeps the trial quotient inside a signed
 * int; the product below wraps, but a wrapped product is still exact modulo
 * 2^64, so the remainder it leaves is only ever one or two multiples high.
 */
function __mc_u64_mod(int $x, int $m): int
{
    if ($m < 0) {   // m >= 2^63: at most one subtraction
        return __mc_u64_lt($x, $m) ? $x : $x - $m;
    }
    if ($x >= 0) { return $x % $m; }
    $q = \intdiv(($x >> 1) & 0x7FFFFFFFFFFFFFFF, $m) << 1;
    $r = $x - $q * $m;
    while (!__mc_u64_lt($r, $m)) { $r = $r - $m; }

    return $r;
}

/** php's rand_range32: one draw, then modulo with the biased tail rejected. */
function __mc_mt_range32(int $umax): int
{
    $r = __mc_mt_op('next');
    if ($umax === 0xFFFFFFFF) { return $r; }
    $m = $umax + 1;
    if (($m & ($m - 1)) === 0) { return $r & ($m - 1); }
    $limit = 0xFFFFFFFF - (0xFFFFFFFF % $m) - 1;
    while ($r > $limit) { $r = __mc_mt_op('next'); }

    return $r % $m;
}

/**
 * php's rand_range64. Two 32-bit draws make the 64-bit word and the FIRST one
 * lands in the LOW half — php shifts each fresh draw UP by the bits already
 * gathered, which is the opposite of the obvious high-word-first reading and the
 * only thing separating this from a sequence that is uniform but not php's.
 */
function __mc_mt_range64(int $umax): int
{
    $lo = __mc_mt_op('next');
    $hi = __mc_mt_op('next');
    $r = ($hi << 32) | $lo;
    if ($umax === -1) { return $r; }        // -1 is UINT64_MAX unsigned
    $m = $umax + 1;
    if (($m & ($m - 1)) === 0) { return $r & ($m - 1); }
    $limit = -1 - __mc_u64_mod(-1, $m) - 1;
    while (__mc_u64_lt($limit, $r)) {
        $lo = __mc_mt_op('next');
        $hi = __mc_mt_op('next');
        $r = ($hi << 32) | $lo;
    }

    return __mc_u64_mod($r, $m);
}

/**
 * php_mt_rand_range — a uniform int in [$min, $max] off the MT engine. The
 * width of the range picks the 32- or 64-bit draw, and the subtraction is
 * allowed to wrap: `PHP_INT_MAX - PHP_INT_MIN` is UINT64_MAX, which is exactly
 * the width being asked for.
 */
function __mc_mt_range(int $min, int $max): int
{
    $umax = $max - $min;
    if ($umax >= 0 && $umax <= 0xFFFFFFFF) {
        return $min + __mc_mt_range32($umax);
    }

    return $min + __mc_mt_range64($umax);
}

/**
 * php_mt_rand_common: what mt_rand($min,$max) uses, and the ONE place the
 * legacy mode still scales instead of sampling. php deliberately keeps this out
 * of php_mt_rand_range so that shuffle() and friends stay unbiased even under
 * MT_RAND_PHP.
 */
function __mc_mt_common(int $min, int $max): int
{
    if (__mc_mt_op('getmode') === 0) {
        return __mc_mt_range($min, $max);
    }
    $n = __mc_mt_op('next') >> 1;

    return $min + (int)((float)($max - $min + 1.0) * ($n / (2147483647 + 1.0)));
}

/**
 * Seed the MT engine. A null seed takes one from the OS, as php does. $mode is
 * MT_RAND_MT19937 (0) or the deprecated MT_RAND_PHP (1); php raises a
 * deprecation for the latter and this does not.
 */
function mt_srand(?int $seed = null, int $mode = 0): void
{
    __mc_mt_op('mode', $mode);
    __mc_mt_op('srand', $seed === null ? \random_int(0, 0xFFFFFFFF) : $seed);
}

/** srand() is mt_srand() — php merged the two engines in 7.1. */
function srand(?int $seed = null, int $mode = 0): void
{
    \mt_srand($seed, $mode);
}

/**
 * A random int. With no arguments, 31 bits in [0, mt_getrandmax()]; with both,
 * a uniform value in the inclusive range. php throws for a reversed range.
 */
function mt_rand(?int $min = null, ?int $max = null): int
{
    if ($min === null && $max === null) { return __mc_mt_op('next') >> 1; }
    if ($min === null || $max === null) {
        throw new \ArgumentCountError('mt_rand() expects exactly 0 or 2 arguments, 1 given');
    }
    if ($min > $max) {
        throw new \ValueError('mt_rand(): Argument #2 ($max) must be greater than or equal to argument #1 ($min)');
    }

    return __mc_mt_common($min, $max);
}

/**
 * rand() is mt_rand() with one legacy kindness php kept: a reversed range is
 * silently swapped rather than rejected.
 */
function rand(?int $min = null, ?int $max = null): int
{
    if ($min === null && $max === null) { return __mc_mt_op('next') >> 1; }
    if ($min === null || $max === null) {
        throw new \ArgumentCountError('rand() expects exactly 0 or 2 arguments, 1 given');
    }
    if ($max < $min) { return __mc_mt_common($max, $min); }

    return __mc_mt_common($min, $max);
}

function mt_getrandmax(): int
{
    return 2147483647;
}

function getrandmax(): int
{
    return 2147483647;
}

/**
 * `str_shuffle` — php_string_shuffle verbatim: Fisher-Yates walked downwards
 * off php_mt_rand_range, so a seeded run matches php byte for byte.
 */
function str_shuffle(string $string): string
{
    $n = \strlen($string);
    if ($n <= 1) { return $string; }
    $b = [];
    for ($i = 0; $i < $n; $i = $i + 1) { $b[$i] = $string[$i]; }
    $left = $n - 1;
    while ($left > 0) {
        $j = __mc_mt_range(0, $left);
        if ($j !== $left) {
            $t = $b[$left];
            $b[$left] = $b[$j];
            $b[$j] = $t;
        }
        $left = $left - 1;
    }

    return \implode('', $b);
}
