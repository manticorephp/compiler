<?php

namespace Parser\Ast;

/** Tolerant mode only: a class member the parser could not use; `skipped` counts the tokens dropped to resynchronise. */
final class ErrorMember
{
    public function __construct(
        public readonly Span $span,
        public readonly int $skipped = 0,
    ) {}
}
