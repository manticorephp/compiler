<?php

namespace Parser\Ast;

/**
 * Class property declaration.
 *
 * Carries visibility, static/readonly/abstract/final flags, an optional
 * type hint, the variable name (without leading $), an optional default
 * expression, and any attributes.
 */
final class PropertyDecl
{
    /**
     * @param AttributeNode[] $attributes
     */
    public function __construct(
        public readonly string $name,
        public readonly string $visibility,   // 'public' | 'protected' | 'private'
        public readonly bool $isStatic,
        public readonly bool $isReadonly,
        public readonly ?string $typeHint,
        public readonly ?Expr $default,
        public readonly array $attributes,
        public readonly Span $span,
        public readonly ?string $docComment = null,
    ) {}
}
