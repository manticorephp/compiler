<?php

namespace Parser\Ast;

/**
 * A single attribute applied to a class, function, method, property,
 * parameter, or const declaration.
 *
 * Example: `#[Attribute(Attribute::TARGET_CLASS)]` → name = "Attribute",
 *          args = [Expr::staticAccess(...)]
 */
final class AttributeNode
{
    /**
     * @param Expr[] $args
     */
    public function __construct(
        public readonly string $name,
        public readonly array $args,
        public readonly Span $span,
    ) {}
}
