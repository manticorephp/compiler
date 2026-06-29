<?php

namespace Ffi;

use Attribute;

/**
 * Names the C symbol that a PHP function or method binds to.
 *
 * Used together with {@see Library}. The decorated function should have an
 * empty body — the compiler treats it as an extern declaration.
 */
#[Attribute(Attribute::TARGET_FUNCTION | Attribute::TARGET_METHOD)]
final class Symbol
{
    public function __construct(
        public readonly string $name,
    ) {}
}
