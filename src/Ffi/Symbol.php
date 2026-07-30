<?php

namespace Ffi;

use Attribute;

/**
 * Names the C symbol a PHP FREE FUNCTION binds to.
 *
 * Used together with {@see Library}. The decorated function's body is never
 * lowered — the compiler emits a thin wrapper calling the C symbol directly —
 * so keep it trivial (`{}`, `return 0;`). That body is the Zend fallback: the
 * same source also runs under stock PHP during the cold bootstrap, where the
 * attribute is inert and the body executes instead.
 *
 * ⚠ Free functions only; `#[Symbol]` on a method is a compile error. The
 * lowered method would carry a receiver parameter with no C counterpart, so
 * only a `static` could ever bind — and a static method binding is a namespaced
 * free function with worse ergonomics. It also could not cross a `.o` boundary:
 * a module's `.sig` exports free functions only, which is precisely what makes
 * `Runtime\Libc\*` importable. Group bindings with a namespace, not a class.
 */
#[Attribute(Attribute::TARGET_FUNCTION)]
final class Symbol
{
    public function __construct(
        public readonly string $name,
    ) {}
}
