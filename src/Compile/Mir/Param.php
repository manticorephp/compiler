<?php

namespace Compile\Mir;

final class Param
{
    /** Declared `array` (bare) or a typed array param. A by-value copy-on-entry
     *  separates it when the body mutates it in place (PHP value semantics),
     *  even after a bare `array` hint erased the element type to unknown. */
    public bool $arrayHinted = false;

    public function __construct(
        public readonly string $name,
        public Type $type,
        public readonly bool $byRef = false,
        public readonly bool $variadic = false,
        // Pre-lowered default value (constant expr) for an optional param.
        // Used to pad omitted trailing args at a call site whose receiver
        // class only resolves after InferTypes (typed method calls).
        public readonly ?Node $default = null,
    ) {}
}
