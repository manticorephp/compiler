<?php

namespace Lexer;

/**
 * A {@see Token} that also knows where it ends. Only a lexer scanning in
 * ranged mode makes these: the two extra ints per token are not paid in the
 * compiler's own (strict) path.
 */
class RangedToken extends Token
{
    public function __construct(
        string $kind,
        string $lexeme,
        int $line,
        int $column,
        public readonly int $toLine,
        public readonly int $toColumn,
    ) {
        parent::__construct($kind, $lexeme, $line, $column);
    }

    public function endLine(): int
    {
        return $this->toLine;
    }

    public function endColumn(): int
    {
        return $this->toColumn;
    }
}
