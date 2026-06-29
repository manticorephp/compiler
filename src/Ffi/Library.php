<?php

namespace Ffi;

use Attribute;

/**
 * Marks a class, function, or method as belonging to a foreign library.
 *
 * The compiler reads this attribute to know which shared library should be
 * opened to resolve the bound symbol(s). For now the attribute is stored as
 * metadata; future codegen phases will use it to emit direct LLVM `call`
 * instructions to the linked symbol (see docs/bootstrap/01-ffi-design.md).
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_FUNCTION | Attribute::TARGET_METHOD)]
final class Library
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $version = null,
    ) {}
}
