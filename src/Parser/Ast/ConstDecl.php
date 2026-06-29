<?php

namespace Parser\Ast;

/**
 * `const NAME = value;` — either top-level or inside a class.
 *
 * Inside a class, `visibility` is one of public/protected/private; for
 * top-level constants it stays an empty string.
 */
final class ConstDecl
{
    /**
     * @param AttributeNode[] $attributes
     */
    public function __construct(
        public readonly string $name,
        public readonly Expr $value,
        public readonly string $visibility,
        public readonly ?string $typeHint,
        public readonly array $attributes,
        public readonly Span $span,
    ) {}
}
