<?php

namespace Parser\Ast;

/**
 * Function declaration (phase-1 shape).
 *
 * Does not yet carry attributes, by-ref return, generic hints, or
 * doc-comments — those land as later phases extend the parser.
 */
final class FunctionDecl
{
    /**
     * @param Param[] $params
     * @param AttributeNode[] $attributes
     */
    public function __construct(
        public readonly string $name,
        public readonly array $params,
        public readonly ?string $returnType,
        public readonly Block $body,
        public readonly Span $span,
        public readonly bool $returnsByRef = false,
        public readonly ?string $docComment = null,
        public readonly array $attributes = [],
    ) {}
}
