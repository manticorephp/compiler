<?php

namespace Parser\Ast;

/**
 * Brace-delimited sequence of statements. Used by function bodies, control
 * flow arms, etc.
 */
final class Block
{
    /**
     * @param Stmt[] $statements
     */
    public function __construct(
        public readonly array $statements,
    ) {}
}
