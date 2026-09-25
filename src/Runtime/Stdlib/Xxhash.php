<?php

// XXH3-128 (hash('xxh128')), seed 0, default secret — a port of the scalar path
// of php's bundled ext/hash/xxhash/xxhash.h. Every u64 op is spelled with 32-bit
// halves so the same source runs under Zend (where an int overflow turns into a
// float) and natively; the byte-for-byte oracle is php's hash('xxh128').

function __mc_xxh_secret(): string
{
    return "\xb8\xfe\x6c\x39\x23\xa4\x4b\xbe\x7c\x01\x81\x2c\xf7\x21\xad\x1c"
        . "\xde\xd4\x6d\xe9\x83\x90\x97\xdb\x72\x40\xa4\xa4\xb7\xb3\x67\x1f"
        . "\xcb\x79\xe6\x4e\xcc\xc0\xe5\x78\x82\x5a\xd0\x7d\xcc\xff\x72\x21"
        . "\xb8\x08\x46\x74\xf7\x43\x24\x8e\xe0\x35\x90\xe6\x81\x3a\x26\x4c"
        . "\x3c\x28\x52\xbb\x91\xc3\x00\xcb\x88\xd0\x65\x8b\x1b\x53\x2e\xa3"
        . "\x71\x64\x48\x97\xa2\x0d\xf9\x4e\x38\x19\xef\x46\xa9\xde\xac\xd8"
        . "\xa8\xfa\x76\x3f\xe3\x9c\x34\x3f\xf9\xdc\xbb\xc7\xc7\x0b\x4f\x1d"
        . "\x8a\x51\xe0\x4b\xcd\xb4\x59\x31\xc8\x9f\x7e\xc9\xd9\x78\x73\x64"
        . "\xea\xc5\xac\x83\x34\xd3\xeb\xc3\xc5\x81\xa0\xff\xfa\x13\x63\xeb"
        . "\x17\x0d\xdd\x51\xb7\xf0\xda\x49\xd3\x16\x55\x26\x29\xd4\x68\x9e"
        . "\x2b\x16\xbe\x58\x7d\x47\xa1\xfc\x8f\xf8\xb8\xd1\x7a\xd0\x31\xce"
        . "\x45\xcb\x3a\x8f\x95\x16\x04\x28\xaf\xd7\xfb\xca\xbb\x4b\x40\x7e";
}

function __mc_xxh_add(int $a, int $b): int
{
    $lo = ($a & 0xFFFFFFFF) + ($b & 0xFFFFFFFF);
    $hi = (($a >> 32) & 0xFFFFFFFF) + (($b >> 32) & 0xFFFFFFFF) + ($lo >> 32);
    return (($hi & 0xFFFFFFFF) << 32) | ($lo & 0xFFFFFFFF);
}

function __mc_xxh_sub(int $a, int $b): int
{
    return \__mc_xxh_add($a, \__mc_xxh_add(~$b, 1));
}

/** Logical right shift, 1 <= $n <= 63. */
function __mc_xxh_shr(int $a, int $n): int
{
    return ($a >> $n) & (\PHP_INT_MAX >> ($n - 1));
}

/** u32 × u32 → u64. */
function __mc_xxh_m32(int $a, int $b): int
{
    return \__mc_xxh_add((($a >> 16) * $b) << 16, ($a & 0xFFFF) * $b);
}

/** Low 64 bits of u64 × u64. */
function __mc_xxh_mul(int $a, int $b): int
{
    $al = $a & 0xFFFFFFFF;
    $ah = ($a >> 32) & 0xFFFFFFFF;
    $bl = $b & 0xFFFFFFFF;
    $bh = ($b >> 32) & 0xFFFFFFFF;
    $cross = \__mc_xxh_add(\__mc_xxh_m32($al, $bh), \__mc_xxh_m32($ah, $bl));
    return \__mc_xxh_add(\__mc_xxh_m32($al, $bl), $cross << 32);
}

/**
 * u64 × u64 → [low64, high64].
 * @return int[]
 */
function __mc_xxh_mul128(int $a, int $b): array
{
    $al = $a & 0xFFFFFFFF;
    $ah = ($a >> 32) & 0xFFFFFFFF;
    $bl = $b & 0xFFFFFFFF;
    $bh = ($b >> 32) & 0xFFFFFFFF;
    $ll = \__mc_xxh_m32($al, $bl);
    $hl = \__mc_xxh_m32($ah, $bl);
    $lh = \__mc_xxh_m32($al, $bh);
    $hh = \__mc_xxh_m32($ah, $bh);
    $cross = \__mc_xxh_add(\__mc_xxh_add(\__mc_xxh_shr($ll, 32), $hl & 0xFFFFFFFF), $lh);
    $upper = \__mc_xxh_add(\__mc_xxh_add(\__mc_xxh_shr($hl, 32), \__mc_xxh_shr($cross, 32)), $hh);
    return [($cross << 32) | ($ll & 0xFFFFFFFF), $upper];
}

function __mc_xxh_fold(int $a, int $b): int
{
    $m = \__mc_xxh_mul128($a, $b);
    return $m[0] ^ $m[1];
}

function __mc_xxh_rd32(string $s, int $o): int
{
    return \ord($s[$o]) | (\ord($s[$o + 1]) << 8) | (\ord($s[$o + 2]) << 16) | (\ord($s[$o + 3]) << 24);
}

function __mc_xxh_rd64(string $s, int $o): int
{
    return \__mc_xxh_rd32($s, $o) | (\__mc_xxh_rd32($s, $o + 4) << 32);
}

function __mc_xxh_swap32(int $x): int
{
    return (($x & 0xFF) << 24) | (($x & 0xFF00) << 8) | (($x >> 8) & 0xFF00) | (($x >> 24) & 0xFF);
}

function __mc_xxh_swap64(int $x): int
{
    $r = 0;
    for ($i = 0; $i < 8; $i = $i + 1) {
        $r = ($r << 8) | (($x >> (8 * $i)) & 0xFF);
    }
    return $r;
}

function __mc_xxh64_avalanche(int $h): int
{
    $h = $h ^ \__mc_xxh_shr($h, 33);
    $h = \__mc_xxh_mul($h, -4417276706812531889);
    $h = $h ^ \__mc_xxh_shr($h, 29);
    $h = \__mc_xxh_mul($h, 1609587929392839161);
    return $h ^ \__mc_xxh_shr($h, 32);
}

function __mc_xxh3_avalanche(int $h): int
{
    $h = $h ^ \__mc_xxh_shr($h, 37);
    $h = \__mc_xxh_mul($h, 1609587791953885689);
    return $h ^ \__mc_xxh_shr($h, 32);
}

function __mc_xxh_mix16(string $in, int $io, string $sec, int $so): int
{
    return \__mc_xxh_fold(
        \__mc_xxh_rd64($in, $io) ^ \__mc_xxh_rd64($sec, $so),
        \__mc_xxh_rd64($in, $io + 8) ^ \__mc_xxh_rd64($sec, $so + 8)
    );
}

/**
 * XXH128_mix32B over [low64, high64].
 * @param int[] $acc
 * @return int[]
 */
function __mc_xxh_mix32(array $acc, string $in, int $i1, int $i2, string $sec, int $so): array
{
    $lo = \__mc_xxh_add($acc[0], \__mc_xxh_mix16($in, $i1, $sec, $so));
    $lo = $lo ^ \__mc_xxh_add(\__mc_xxh_rd64($in, $i2), \__mc_xxh_rd64($in, $i2 + 8));
    $hi = \__mc_xxh_add($acc[1], \__mc_xxh_mix16($in, $i2, $sec, $so + 16));
    $hi = $hi ^ \__mc_xxh_add(\__mc_xxh_rd64($in, $i1), \__mc_xxh_rd64($in, $i1 + 8));
    return [$lo, $hi];
}

/**
 * Final fold shared by the 17..240-byte paths.
 * @param int[] $acc
 * @return int[]
 */
function __mc_xxh_mid_final(array $acc, int $len): array
{
    $lo = \__mc_xxh_add($acc[0], $acc[1]);
    $hi = \__mc_xxh_add(
        \__mc_xxh_add(\__mc_xxh_mul($acc[0], -7046029288634856825), \__mc_xxh_mul($acc[1], -8796714831421723037)),
        \__mc_xxh_mul($len, -4417276706812531889)
    );
    return [\__mc_xxh3_avalanche($lo), \__mc_xxh_sub(0, \__mc_xxh3_avalanche($hi))];
}

/**
 * XXH3_mergeAccs.
 * @param int[] $acc
 */
function __mc_xxh_merge(array $acc, string $sec, int $so, int $start): int
{
    $r = $start;
    for ($i = 0; $i < 4; $i = $i + 1) {
        $r = \__mc_xxh_add($r, \__mc_xxh_fold(
            $acc[2 * $i] ^ \__mc_xxh_rd64($sec, $so + 16 * $i),
            $acc[2 * $i + 1] ^ \__mc_xxh_rd64($sec, $so + 16 * $i + 8)
        ));
    }
    return \__mc_xxh3_avalanche($r);
}

/**
 * XXH3_accumulate_512 of the stripe at $io with the secret at $so.
 * @param int[] $acc
 * @return int[]
 */
function __mc_xxh_acc512(array $acc, string $in, int $io, string $sec, int $so): array
{
    for ($i = 0; $i < 8; $i = $i + 1) {
        $dv = \__mc_xxh_rd64($in, $io + 8 * $i);
        $dk = $dv ^ \__mc_xxh_rd64($sec, $so + 8 * $i);
        $acc[$i ^ 1] = \__mc_xxh_add($acc[$i ^ 1], $dv);
        $acc[$i] = \__mc_xxh_add($acc[$i], \__mc_xxh_m32($dk & 0xFFFFFFFF, \__mc_xxh_shr($dk, 32)));
    }
    return $acc;
}

/**
 * XXH3_hashLong_128b with the default 192-byte secret.
 * @return int[]
 */
function __mc_xxh128_long(string $in, int $len, string $sec): array
{
    $acc = [0xC2B2AE3D, -7046029288634856825, -4417276706812531889, 1609587929392839161,
        -8796714831421723037, 0x85EBCA77, 2870177450012600261, 0x9E3779B1];
    $nbBlocks = \intdiv($len - 1, 1024);
    for ($n = 0; $n < $nbBlocks; $n = $n + 1) {
        for ($s = 0; $s < 16; $s = $s + 1) {
            $acc = \__mc_xxh_acc512($acc, $in, $n * 1024 + $s * 64, $sec, $s * 8);
        }
        for ($i = 0; $i < 8; $i = $i + 1) {
            $a = $acc[$i];
            $a = $a ^ \__mc_xxh_shr($a, 47);
            $a = $a ^ \__mc_xxh_rd64($sec, 128 + 8 * $i);
            $acc[$i] = \__mc_xxh_mul($a, 0x9E3779B1);
        }
    }
    $nbStripes = \intdiv(($len - 1) - 1024 * $nbBlocks, 64);
    for ($s = 0; $s < $nbStripes; $s = $s + 1) {
        $acc = \__mc_xxh_acc512($acc, $in, $nbBlocks * 1024 + $s * 64, $sec, $s * 8);
    }
    $acc = \__mc_xxh_acc512($acc, $in, $len - 64, $sec, 121);
    return [
        \__mc_xxh_merge($acc, $sec, 11, \__mc_xxh_mul($len, -7046029288634856825)),
        \__mc_xxh_merge($acc, $sec, 117, ~\__mc_xxh_mul($len, -4417276706812531889)),
    ];
}

/**
 * XXH3_128bits($s) as [low64, high64].
 * @return int[]
 */
function __mc_xxh128(string $in): array
{
    $sec = \__mc_xxh_secret();
    $len = \strlen($in);
    if ($len === 0) {
        return [
            \__mc_xxh64_avalanche(\__mc_xxh_rd64($sec, 64) ^ \__mc_xxh_rd64($sec, 72)),
            \__mc_xxh64_avalanche(\__mc_xxh_rd64($sec, 80) ^ \__mc_xxh_rd64($sec, 88)),
        ];
    }
    if ($len <= 3) {
        $c1 = \ord($in[0]);
        $c2 = \ord($in[$len >> 1]);
        $c3 = \ord($in[$len - 1]);
        $cl = ($c1 << 16) | ($c2 << 24) | $c3 | ($len << 8);
        $sw = \__mc_xxh_swap32($cl);
        $ch = (($sw << 13) | ($sw >> 19)) & 0xFFFFFFFF;
        $bl = \__mc_xxh_rd32($sec, 0) ^ \__mc_xxh_rd32($sec, 4);
        $bh = \__mc_xxh_rd32($sec, 8) ^ \__mc_xxh_rd32($sec, 12);
        return [\__mc_xxh64_avalanche($cl ^ $bl), \__mc_xxh64_avalanche($ch ^ $bh)];
    }
    if ($len <= 8) {
        $in64 = \__mc_xxh_rd32($in, 0) | (\__mc_xxh_rd32($in, $len - 4) << 32);
        $keyed = $in64 ^ \__mc_xxh_rd64($sec, 16) ^ \__mc_xxh_rd64($sec, 24);
        $m = \__mc_xxh_mul128($keyed, \__mc_xxh_add(-7046029288634856825, $len << 2));
        $hi = \__mc_xxh_add($m[1], $m[0] << 1);
        $lo = $m[0] ^ \__mc_xxh_shr($hi, 3);
        $lo = $lo ^ \__mc_xxh_shr($lo, 35);
        $lo = \__mc_xxh_mul($lo, -6939452855193903323);
        $lo = $lo ^ \__mc_xxh_shr($lo, 28);
        return [$lo, \__mc_xxh3_avalanche($hi)];
    }
    if ($len <= 16) {
        $bl = \__mc_xxh_rd64($sec, 32) ^ \__mc_xxh_rd64($sec, 40);
        $bh = \__mc_xxh_rd64($sec, 48) ^ \__mc_xxh_rd64($sec, 56);
        $ih = \__mc_xxh_rd64($in, $len - 8);
        $m = \__mc_xxh_mul128(\__mc_xxh_rd64($in, 0) ^ $ih ^ $bl, -7046029288634856825);
        $ml = \__mc_xxh_add($m[0], ($len - 1) << 54);
        $ih = $ih ^ $bh;
        $mh = \__mc_xxh_add(\__mc_xxh_add($m[1], $ih), \__mc_xxh_m32($ih & 0xFFFFFFFF, 0x85EBCA76));
        $ml = $ml ^ \__mc_xxh_swap64($mh);
        $h = \__mc_xxh_mul128($ml, -4417276706812531889);
        $hh = \__mc_xxh_add($h[1], \__mc_xxh_mul($mh, -4417276706812531889));
        return [\__mc_xxh3_avalanche($h[0]), \__mc_xxh3_avalanche($hh)];
    }
    if ($len <= 128) {
        $acc = [\__mc_xxh_mul($len, -7046029288634856825), 0];
        if ($len > 32) {
            if ($len > 64) {
                if ($len > 96) {
                    $acc = \__mc_xxh_mix32($acc, $in, 48, $len - 64, $sec, 96);
                }
                $acc = \__mc_xxh_mix32($acc, $in, 32, $len - 48, $sec, 64);
            }
            $acc = \__mc_xxh_mix32($acc, $in, 16, $len - 32, $sec, 32);
        }
        $acc = \__mc_xxh_mix32($acc, $in, 0, $len - 16, $sec, 0);
        return \__mc_xxh_mid_final($acc, $len);
    }
    if ($len <= 240) {
        $acc = [\__mc_xxh_mul($len, -7046029288634856825), 0];
        for ($i = 0; $i < 4; $i = $i + 1) {
            $acc = \__mc_xxh_mix32($acc, $in, 32 * $i, 32 * $i + 16, $sec, 32 * $i);
        }
        $acc = [\__mc_xxh3_avalanche($acc[0]), \__mc_xxh3_avalanche($acc[1])];
        $rounds = $len >> 5;
        for ($i = 4; $i < $rounds; $i = $i + 1) {
            $acc = \__mc_xxh_mix32($acc, $in, 32 * $i, 32 * $i + 16, $sec, 3 + 32 * ($i - 4));
        }
        $acc = \__mc_xxh_mix32($acc, $in, $len - 16, $len - 32, $sec, 103);
        return \__mc_xxh_mid_final($acc, $len);
    }
    return \__mc_xxh128_long($in, $len, $sec);
}

/** hash('xxh128') bytes: the canonical big-endian high64 then low64. */
function __mc_xxh128_raw(string $in): string
{
    $h = \__mc_xxh128($in);
    return \__mc_u32be(\__mc_xxh_shr($h[1], 32)) . \__mc_u32be($h[1] & 0xFFFFFFFF)
        . \__mc_u32be(\__mc_xxh_shr($h[0], 32)) . \__mc_u32be($h[0] & 0xFFFFFFFF);
}
