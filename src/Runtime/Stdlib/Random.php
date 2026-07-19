<?php

// Cryptographically-secure randomness — random_bytes / random_int — on libc
// getentropy(2) (Runtime\Libc\sys_getentropy), the cross-host CSPRNG. Global
// php.net surface. (The legacy mt_rand/rand Mersenne-Twister engine is a separate
// future item — it needs a php-exact MT19937 to be difftest-able.)

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
