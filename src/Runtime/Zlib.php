<?php

namespace Runtime\Zlib;

use Ffi\CType;
use Ffi\Library;
use Ffi\Ptr;
use Ffi\Symbol;

// Thin FFI binding to the host libz — the engine under ext/zlib (Stdlib/Zlib.php).
// Same library php links, so gzdeflate() & co. answer php's bytes.
//
// A z_stream is a calloc'd 112-byte block carried as its raw address (`int`); the
// fields PHP touches are poked at their LP64 offsets (Stdlib/Zlib.php,
// __mc_zl_* offset table). zalloc/zfree/opaque stay 0 = libz's own allocator.
// The *Init2_ entry points are what the deflateInit2()/inflateInit2() macros
// expand to: libz checks the version's major digit and sizeof(z_stream).

#[Library('z'), Symbol('zlibVersion')]
function version(): Ptr {}

// int deflateInit2_(z_streamp, int level, int method, int windowBits, int memLevel, int strategy, const char *version, int stream_size)
#[Library('z'), Symbol('deflateInit2_'), CType('int')]
function deflateInit2(int $strm, #[CType('int')] int $level, #[CType('int')] int $method, #[CType('int')] int $windowBits,
    #[CType('int')] int $memLevel, #[CType('int')] int $strategy, Ptr $version, #[CType('int')] int $size): int {}

#[Library('z'), Symbol('deflate'), CType('int')]
function deflate(int $strm, #[CType('int')] int $flush): int {}

#[Library('z'), Symbol('deflateEnd'), CType('int')]
function deflateEnd(int $strm): int {}

#[Library('z'), Symbol('deflateReset'), CType('int')]
function deflateReset(int $strm): int {}

#[Library('z'), Symbol('deflateSetDictionary'), CType('int')]
function deflateSetDictionary(int $strm, string $dict, #[CType('int')] int $len): int {}

// uLong deflateBound(z_streamp, uLong sourceLen)
#[Library('z'), Symbol('deflateBound')]
function deflateBound(int $strm, int $sourceLen): int {}

// int inflateInit2_(z_streamp, int windowBits, const char *version, int stream_size)
#[Library('z'), Symbol('inflateInit2_'), CType('int')]
function inflateInit2(int $strm, #[CType('int')] int $windowBits, Ptr $version, #[CType('int')] int $size): int {}

#[Library('z'), Symbol('inflate'), CType('int')]
function inflate(int $strm, #[CType('int')] int $flush): int {}

#[Library('z'), Symbol('inflateEnd'), CType('int')]
function inflateEnd(int $strm): int {}

#[Library('z'), Symbol('inflateReset'), CType('int')]
function inflateReset(int $strm): int {}

#[Library('z'), Symbol('inflateSetDictionary'), CType('int')]
function inflateSetDictionary(int $strm, string $dict, #[CType('int')] int $len): int {}

// memcpy with a PHP string as the source: the input bytes move into a C buffer
// whose address next_in may hold across calls.
#[Library('c'), Symbol('memcpy')]
function copyIn(Ptr $dst, string $src, #[CType('size_t')] int $n): Ptr {}
