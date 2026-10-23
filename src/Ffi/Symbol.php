<?php

namespace Ffi;

use Attribute;

/**
 * Binds a PHP free function to the C symbol of the given name.
 *
 * Use it with {@see Library}. The compiler emits a thin wrapper that calls the
 * C symbol directly, so the function body is never compiled and should be
 * trivial (`{}` or `return 0;`). Only free functions can be bound; `#[Symbol]`
 * on a method is a compile error, so group bindings with a namespace.
 *
 * ```php
 * #[Library('c'), Symbol('atoi'), CType('int')]
 * function c_atoi(string $s): int { return 0; }
 * ```
 *
 * @example doc_ffi_libc_call
 * @see Library
 * @see CType
 */
// Implementation notes (previous docblock, kept verbatim):
// Names the C symbol a PHP FREE FUNCTION binds to.
//
// Used together with {@see Library}. The decorated function's body is never
// lowered — the compiler emits a thin wrapper calling the C symbol directly —
// so keep it trivial (`{}`, `return 0;`). That body is the Zend fallback: the
// same source also runs under stock PHP during the cold bootstrap, where the
// attribute is inert and the body executes instead.
//
// ⚠ Free functions only; `#[Symbol]` on a method is a compile error. The
// lowered method would carry a receiver parameter with no C counterpart, so
// only a `static` could ever bind — and a static method binding is a namespaced
// free function with worse ergonomics. It also could not cross a `.o` boundary:
// a module's `.sig` exports free functions only, which is precisely what makes
// `Runtime\Libc\*` importable. Group bindings with a namespace, not a class.

#[Attribute(Attribute::TARGET_FUNCTION)]
final class Symbol
{
    /**
     * Create the attribute.
     *
     * @param string $name  the C symbol name
     */
    public function __construct(
        public readonly string $name,
    ) {}
}
