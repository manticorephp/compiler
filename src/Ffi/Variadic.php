<?php

namespace Ffi;

use Attribute;

/**
 * Marks a binding as a C variadic function (`ret f(a, b, ...)`).
 *
 * `$fixed` is the number of named parameters before the `...`; the remaining
 * bound parameters are the variadic arguments. The wrapper is then emitted with a
 * variadic call type so the target's ABI places the extra arguments correctly
 * (on Darwin arm64 they go on the stack). `$fixed` must lie between 0 and the
 * binding's arity, otherwise compilation fails. Both `#[Variadic(2)]` and
 * `#[Variadic(fixed: 2)]` are accepted.
 *
 * ```php
 * #[Library('c'), Symbol('fcntl'), Variadic(2), CType('int')]
 * function fcntl(#[CType('int')] int $fd, #[CType('int')] int $cmd,
 *                #[CType('int')] int $arg): int { return -1; }
 * ```
 *
 * @see Symbol
 * @see CType
 */
// Implementation notes (previous docblock, kept verbatim):
// Marks an FFI binding as a C VARIADIC function (`ret f(a, b, ...)`).
//
// `$fixed` is the number of NAMED (non-variadic) parameters — the count before
// the `...`. The remaining bound parameters are the variadic arguments.
//
// Why it matters: on Darwin arm64 the variadic calling convention places
// variadic arguments on the STACK, not in registers, so calling a variadic C
// function (fcntl, ioctl, open-with-mode) through the normal fixed-arity FFI
// wrapper hands the callee register garbage where it does `va_arg`. The wrapper
// must emit an LLVM variadic call type — `call ret (t0, …, ...) @sym(...)` — so
// the backend applies the correct per-target ABI.
//
// `$fixed` must be between 0 and the binding's declared arity; anything else is
// a compile error. Both the positional form `#[Variadic(2)]` and the named form
// `#[Variadic(fixed: 2)]` are accepted.
//
//     #[Library('c'), Symbol('fcntl'), Variadic(2), CType('int')]
//     function fcntl(#[CType('int')] int $fd, #[CType('int')] int $cmd,
//                    #[CType('int')] int $arg): int { return -1; }

#[Attribute(Attribute::TARGET_FUNCTION)]
final class Variadic
{
    /**
     * Create the attribute.
     *
     * @param int $fixed  number of named parameters before the `...`
     */
    public function __construct(
        public readonly int $fixed,
    ) {}
}
