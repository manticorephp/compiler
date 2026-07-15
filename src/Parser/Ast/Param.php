<?php

namespace Parser\Ast;

/**
 * Function or method parameter.
 *
 * Carries name, optional type hint, optional default value, and
 * promotion / by-ref / variadic / readonly flags. When `$promoted` is
 * non-empty the parameter doubles as a class property declaration —
 * constructor property promotion.
 */
final class Param
{
    /** A by-ref param marked `#[RefOut]`: pure output, safe to auto-vivify
     *  at the caller. Carried across the interface `.sig` (param attributes
     *  themselves do not survive the sig round-trip). */
    public bool $refOut = false;

    /**
     * @param AttributeNode[] $attributes
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $typeHint,
        public readonly ?Expr $default,
        public readonly bool $byRef,
        public readonly bool $variadic,
        /** Visibility for promoted properties: '', 'public', 'protected', 'private'. */
        public readonly string $promoted,
        public readonly bool $promotedReadonly,
        public readonly array $attributes,
        public readonly Span $span,
    ) {}
}
