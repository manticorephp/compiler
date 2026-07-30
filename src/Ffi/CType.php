<?php

namespace Ffi;

use Attribute;

/**
 * Declares the C-side type when PHP's is too coarse. Written at FUNCTION
 * level, alongside {@see Library} / {@see Symbol}:
 *
 *     #[Library('c'), Symbol('signalfd'), CType('int')]
 *     function signalfd(int $fd, \Ffi\Ptr $mask, int $flags): int { return -1; }
 *
 * ONE token is acted on: `'int'`, meaning the C callee's RETURN is a 32-bit
 * `int`, so the wrapper must SIGN-EXTEND it into the i64 carrier. Without it a
 * C `-1` reads back as 4294967295 (`mov w0, #-1` zeroes x0's upper half) — the
 * bug that turned SSL_read's WANT_READ into a 4 GB memmove.
 *
 * ⚠ NEVER on a binding returning a pointer, or a long / ssize_t / size_t /
 * off_t carried as PHP `int` — the sign extension truncates the value.
 *
 * Every other token, and every parameter-position use, is accepted and IGNORED.
 * See `docs/ffi.md`.
 */
#[Attribute(Attribute::TARGET_FUNCTION | Attribute::TARGET_PARAMETER)]
final class CType
{
    public function __construct(
        public readonly string $type,
    ) {}
}
