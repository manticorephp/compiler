<?php

namespace Ffi;

use Attribute;

/**
 * Disambiguate the C-side type of a parameter or return value when
 * the PHP type alone is too coarse (e.g. PHP `int` could be int32_t,
 * size_t, off_t depending on the C signature).
 *
 *     #[Library('c'), Symbol('write')]
 *     function write(
 *         #[CType('int')] int $fd,
 *         \Ffi\Ptr $buf,
 *         #[CType('size_t')] int $count,
 *     ): #[CType('ssize_t')] int {}
 *
 * Recognised tokens: `int`, `int8_t`..`int64_t`, `uint8_t`..`uint64_t`,
 * `size_t`, `ssize_t`, `off_t`, `char`, `void`. `char*` is implicit
 * for PHP `string` parameters and `\Ffi\Ptr` is `void*`.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class CType
{
    public function __construct(
        public readonly string $type,
    ) {}
}
