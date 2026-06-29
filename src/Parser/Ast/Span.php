<?php

namespace Parser\Ast;

/**
 * Source position. 1-based line and column of the first token in a node.
 */
final class Span
{
    public function __construct(
        public readonly int $line,
        public readonly int $column,
    ) {}
}
