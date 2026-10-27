<?php

namespace Parser\Ast;

/**
 * One written name occurrence: where it stands, how it was spelled, and what the
 * parser statically resolved it to. $kind is class | function | constant |
 * namespace | use. $globalFallback is true for an unqualified function or
 * constant name that PHP falls back to the global namespace for at run time
 * (the parser cannot know whether that global symbol exists). $role is empty,
 * the imported kind (class | function | constant) on a `use` entry, or `decl` on a
 * declaration name (then $kind is class | function | member; enum cases, class constants,
 * methods and properties are members, never global constants).
 */
final class NameRef
{
    public function __construct(
        public readonly Span $span,
        public readonly string $written,
        public readonly string $resolved,
        public readonly string $kind,
        public readonly bool $globalFallback,
        public readonly string $role = '',
    ) {}
}
