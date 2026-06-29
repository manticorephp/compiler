<?php

namespace Parser\Ast;

/**
 * Class method declaration.
 *
 * Body is null for abstract/interface methods. The `params` array reuses
 * {@see Param} but with extra promotion/visibility metadata captured in
 * the parser pass that lowers class bodies.
 */
final class MethodDecl
{
    /**
     * @param Param[]         $params
     * @param AttributeNode[] $attributes
     */
    public function __construct(
        public readonly string $name,
        public readonly string $visibility,
        public readonly bool $isStatic,
        public readonly bool $isFinal,
        public readonly bool $isAbstract,
        public readonly array $params,
        public readonly ?string $returnType,
        public readonly ?Block $body,
        public readonly array $attributes,
        public readonly Span $span,
        public readonly bool $returnsByRef = false,
        public readonly ?string $docComment = null,
    ) {}
}
