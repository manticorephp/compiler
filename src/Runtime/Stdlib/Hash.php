<?php

// Hashing (ext/hash + the md5/sha1/crc32 core functions) on the host libcrypto's
// one-shot EVP_Digest/HMAC (Runtime\Crypto). Hex output reuses bin2hex; the raw
// digest bytes come back from a calloc'd buffer via str_from_buffer. crc32 is
// pure PHP (no libcrypto). Global-namespace php.net surface.

/**
 * Algorithm id for a hash name (case-insensitive): 0 md5, 1 sha1, 2 sha224,
 * 3 sha256, 4 sha384, 5 sha512; -1 when unsupported. Ints, so the digest length
 * and EVP selector below stay branchy-but-allocation-free.
 */
function __mc_algo_id(string $algo): int
{
    $a = \strtolower($algo);
    if ($a === 'md5') { return 0; }
    if ($a === 'sha1') { return 1; }
    if ($a === 'sha224') { return 2; }
    if ($a === 'sha256') { return 3; }
    if ($a === 'sha384') { return 4; }
    if ($a === 'sha512') { return 5; }
    return -1;
}

/** The digest byte length for an algo id. */
function __mc_algo_len(int $id): int
{
    if ($id === 0) { return 16; }   // md5
    if ($id === 1) { return 20; }   // sha1
    if ($id === 2) { return 28; }   // sha224
    if ($id === 3) { return 32; }   // sha256
    if ($id === 4) { return 48; }   // sha384
    return 64;                      // sha512
}

/** The opaque `const EVP_MD *` (raw i64) for an algo id. */
function __mc_algo_evp(int $id): int
{
    if ($id === 0) { return \Runtime\Crypto\md5(); }
    if ($id === 1) { return \Runtime\Crypto\sha1(); }
    if ($id === 2) { return \Runtime\Crypto\sha224(); }
    if ($id === 3) { return \Runtime\Crypto\sha256(); }
    if ($id === 4) { return \Runtime\Crypto\sha384(); }
    return \Runtime\Crypto\sha512();
}

/** Raw digest bytes of $data under algo id, via one-shot EVP_Digest. */
function __mc_raw_digest(int $id, string $data): string
{
    $len = \__mc_algo_len($id);
    $md = \Runtime\Libc\calloc($len, 1);
    $sz = \Runtime\Libc\calloc(4, 1);
    if ($md === null || $sz === null) {
        if ($md !== null) { \Runtime\Libc\free($md); }
        if ($sz !== null) { \Runtime\Libc\free($sz); }
        return '';
    }
    \Runtime\Crypto\evpDigest($data, \strlen($data), $md, $sz, \__mc_algo_evp($id), \int_to_ptr(0));
    $out = \str_from_buffer($md, $len);
    \Runtime\Libc\free($md);
    \Runtime\Libc\free($sz);
    return $out;
}

function md5(string $string, bool $binary = false): string
{
    $raw = \__mc_raw_digest(0, $string);
    return $binary ? $raw : \bin2hex($raw);
}

function sha1(string $string, bool $binary = false): string
{
    $raw = \__mc_raw_digest(1, $string);
    return $binary ? $raw : \bin2hex($raw);
}

function md5_file(string $filename, bool $binary = false): string|false
{
    $c = \file_get_contents($filename);
    if ($c === false) {
        return false;
    }
    return \md5($c, $binary);
}

function sha1_file(string $filename, bool $binary = false): string|false
{
    $c = \file_get_contents($filename);
    if ($c === false) {
        return false;
    }
    return \sha1($c, $binary);
}

/**
 * hash($algo, $data, $binary). Supports the EVP digests plus crc32b (== crc32())
 * and crc32 (the MSB-first variant). Returns false on an unknown algo.
 */
function hash(string $algo, string $data, bool $binary = false): string
{
    $a = \strtolower($algo);
    if ($a === 'crc32b') {
        $v = \__mc_crc32b($data);
        return $binary ? \__mc_u32be($v) : \sprintf('%08x', $v);
    }
    if ($a === 'crc32') {
        // php's hash('crc32') is CRC-32/BZIP2 (MSB-first, xorout 0xFFFFFFFF)
        // emitted LITTLE-endian — unlike crc32b, which is big-endian.
        $v = \__mc_crc32_msb($data);
        return $binary ? \__mc_u32le($v) : \bin2hex(\__mc_u32le($v));
    }
    $id = \__mc_algo_id($algo);
    if ($id < 0) {
        throw new \ValueError('hash(): Argument #1 ($algo) must be a valid hashing algorithm');
    }
    $raw = \__mc_raw_digest($id, $data);
    return $binary ? $raw : \bin2hex($raw);
}

function hash_file(string $algo, string $filename, bool $binary = false): string|false
{
    $c = \file_get_contents($filename);
    if ($c === false) {
        return false;
    }
    return \hash($algo, $c, $binary);
}

/**
 * hash_hmac($algo, $data, $key, $binary). One-shot HMAC via libcrypto. Only the
 * EVP digests are keyed-hash capable (crc32 is not an HMAC algo in php either).
 */
function hash_hmac(string $algo, string $data, string $key, bool $binary = false): string
{
    $id = \__mc_algo_id($algo);
    if ($id < 0) {
        throw new \ValueError('hash_hmac(): Argument #1 ($algo) must be a valid hashing algorithm');
    }
    $len = \__mc_algo_len($id);
    $md = \Runtime\Libc\calloc($len, 1);
    $sz = \Runtime\Libc\calloc(4, 1);
    if ($md === null || $sz === null) {
        if ($md !== null) { \Runtime\Libc\free($md); }
        if ($sz !== null) { \Runtime\Libc\free($sz); }
        return false;
    }
    \Runtime\Crypto\hmac(\__mc_algo_evp($id), $key, \strlen($key), $data, \strlen($data), $md, $sz);
    $out = \str_from_buffer($md, $len);
    \Runtime\Libc\free($md);
    \Runtime\Libc\free($sz);
    return $binary ? $out : \bin2hex($out);
}

function hash_hmac_file(string $algo, string $filename, string $key, bool $binary = false): string|false
{
    $c = \file_get_contents($filename);
    if ($c === false) {
        return false;
    }
    return \hash_hmac($algo, $c, $key, $binary);
}

/** @return string[] */
function hash_algos(): array
{
    return ['md5', 'sha1', 'sha224', 'sha256', 'sha384', 'sha512', 'crc32', 'crc32b'];
}

/** php's crc32() — the reflected CRC-32 (IEEE 802.3), same as hash('crc32b'). */
function crc32(string $string): int
{
    return \__mc_crc32b($string);
}

/**
 * Reflected CRC-32 (poly 0xEDB88320, init/xorout 0xFFFFFFFF) — the crc32()
 * value and hash('crc32b'). Bitwise (no table); $crc stays masked to 32 bits so
 * `>>` is a logical shift on a positive int.
 */
function __mc_crc32b(string $s): int
{
    $crc = 0xFFFFFFFF;
    $n = \strlen($s);
    for ($i = 0; $i < $n; $i = $i + 1) {
        $crc = $crc ^ \ord($s[$i]);
        for ($j = 0; $j < 8; $j = $j + 1) {
            $mask = -($crc & 1);
            $crc = (($crc >> 1) ^ (0xEDB88320 & $mask)) & 0xFFFFFFFF;
        }
    }
    return ($crc ^ 0xFFFFFFFF) & 0xFFFFFFFF;
}

/** MSB-first CRC-32/BZIP2 (poly 0x04C11DB7, init/xorout 0xFFFFFFFF, no reflect) —
 *  the value behind php's hash('crc32'). */
function __mc_crc32_msb(string $s): int
{
    $crc = 0xFFFFFFFF;
    $n = \strlen($s);
    for ($i = 0; $i < $n; $i = $i + 1) {
        $crc = $crc ^ ((\ord($s[$i]) << 24) & 0xFFFFFFFF);
        for ($j = 0; $j < 8; $j = $j + 1) {
            $hi = $crc & 0x80000000;
            $crc = ($crc << 1) & 0xFFFFFFFF;
            if ($hi !== 0) {
                $crc = $crc ^ 0x04C11DB7;
            }
        }
    }
    return ($crc ^ 0xFFFFFFFF) & 0xFFFFFFFF;
}

/** A u32 as 4 little-endian bytes (php hash('crc32') binary output). */
function __mc_u32le(int $v): string
{
    return \chr($v & 255) . \chr(($v >> 8) & 255)
        . \chr(($v >> 16) & 255) . \chr(($v >> 24) & 255);
}

/** A u32 as 4 big-endian bytes (php hash('crc32b') binary output). */
function __mc_u32be(int $v): string
{
    return \chr(($v >> 24) & 255) . \chr(($v >> 16) & 255)
        . \chr(($v >> 8) & 255) . \chr($v & 255);
}
