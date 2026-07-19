<?php

namespace Ffi;

use Attribute;

/**
 * Marks an FFI binding as a C VARIADIC function (`ret f(a, b, ...)`).
 *
 * `$fixed` is the number of NAMED (non-variadic) parameters — the count before
 * the `...`. The remaining bound parameters are the variadic arguments.
 *
 * Why it matters: on Darwin arm64 the variadic calling convention places
 * variadic arguments on the STACK, not in registers, so calling a variadic C
 * function (fcntl, ioctl, open-with-mode) through the normal fixed-arity FFI
 * wrapper hands the callee register garbage where it does `va_arg`. With this
 * attribute the wrapper emits an LLVM variadic call type — `call ret (t0, …, ...)
 * @sym(...)` — and the backend applies the correct per-target ABI.
 */
#[Attribute(Attribute::TARGET_FUNCTION | Attribute::TARGET_METHOD)]
final class Variadic
{
    public function __construct(
        public readonly int $fixed,
    ) {}
}
