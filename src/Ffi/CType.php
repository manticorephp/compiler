<?php

namespace Ffi;

use Attribute;

/**
 * Declares the C type of a binding's return value or parameter when PHP's own type is too coarse.
 *
 * Put it on the function to describe the C return type, and on a parameter to
 * describe that argument. The vocabulary is closed: `void`, `bool`, `char`,
 * `uchar`, `short`, `ushort`, `int`, `uint`, `long`, `ulong`, `longlong`,
 * `ulonglong`, `size_t`, `ssize_t`, `off_t`, `float`, `double` and `ptr`, plus
 * multi-word C spellings such as `unsigned int`. Any other name, including a
 * platform typedef like `nfds_t`, is a compile error. `long`, `size_t` and
 * `off_t` are 64-bit.
 *
 * The token decides how a narrow C result is widened to a PHP `int`: a callee
 * returning `int` -1 must be read as -1, not 4294967295, and `uint` extends the
 * other way. The token must agree with the PHP type: an integer token on a
 * `\Ffi\Ptr` or `string` is rejected. Use `'ptr'` for a handle carried as `int`.
 *
 * ```php
 * #[Library('c'), Symbol('write'), CType('ssize_t')]
 * function write(#[CType('int')] int $fd, string $buf,
 *                #[CType('size_t')] int $n): int { return -1; }
 * ```
 *
 * @example doc_ffi_libc_call
 * @see Library
 * @see Symbol
 */
// Implementation notes (previous docblock, kept verbatim):
// Declares the C-side type when PHP's is too coarse. Written at FUNCTION
// level, alongside {@see Library} / {@see Symbol}:
//
//     #[Library('c'), Symbol('signalfd'), CType('int')]
//     function signalfd(int $fd, \Ffi\Ptr $mask, int $flags): int { return -1; }
//
// …and on individual parameters, where the PHP hint is just as coarse:
//
//     function write(#[CType('int')] int $fd, string $buf,
//                    #[CType('size_t')] int $n): int {}
//
// The vocabulary is CLOSED — `void` `bool` `char` `uchar` `short` `ushort`
// `int` `uint` `long` `ulong` `longlong` `ulonglong` `size_t` `ssize_t` `off_t`
// `float` `double` `ptr`, plus the multi-word C spellings (`unsigned int`, …)
// as aliases. Anything else is a COMPILE ERROR, including a platform typedef
// like `nfds_t`: only the binding author can resolve its width, and it is not
// always the same one (glibc `unsigned long`, Darwin `unsigned int`). The table
// is {@see \Compile\Mir\FfiCTypes}; `long`/`size_t`/`off_t` are 64-bit (LP64,
// host == target).
//
// Why the RETURN token is not optional: a C callee returning `int` -1 does
// `mov w0, #-1`, which zeroes x0's upper half, so an i64 declare reads
// 4294967295 — the bug that turned SSL_read's WANT_READ into a 4 GB memmove.
// Signedness picks the direction: `uint` zero-extends where `int` sign-extends.
//
// ⚠ The token must AGREE with the PHP hint, and the compiler enforces it. An
// integer token on a `\Ffi\Ptr` or `string` — anything carrying an address — is
// rejected, because extending or truncating a pointer is the SSL_read failure
// itself. Use `'ptr'` for the handle-as-`int` idiom (`SSL_CTX_new`, `strstr`).
//
// See `docs/ffi.md`.

#[Attribute(Attribute::TARGET_FUNCTION | Attribute::TARGET_PARAMETER)]
final class CType
{
    /**
     * Create the attribute.
     *
     * @param string $type  a C type name from the closed vocabulary, e.g. `'int'`, `'size_t'`, `'ptr'`
     */
    public function __construct(
        public readonly string $type,
    ) {}
}
