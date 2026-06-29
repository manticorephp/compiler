<?php

// Thin zlib extension glue: an FFI binding to libz's `crc32` plus a PHP
// wrapper. Compiled INTO any application that opts in via the manifest's
// `extensions`; libz itself is linked by `cc -lz` (no manticore runtime
// surface — the native lib never touches the arena/rc heap).

use Ffi\Library;
use Ffi\Symbol;

/** uLong crc32(uLong crc, const Bytef *buf, uInt len) */
#[Library('z'), Symbol('crc32')]
function __ffi_crc32(int $crc, string $buf, int $len): int { return 0; }

/** CRC-32 of a string, seeded at 0 (zlib's documented initial value). */
function ext_zlib_crc32(string $s): int
{
    return __ffi_crc32(0, $s, \strlen($s));
}
