<?php

namespace Lexer;

/**
 * A single token emitted by the Lexer.
 *
 * Carries:
 *   - kind   string from {@see TokenKind} constants
 *   - lexeme exact source text the token spans
 *   - line   1-based source line of the first byte
 *   - column 1-based column of the first byte
 *
 * {@see endLine()} / {@see endColumn()} (just past the last byte) are 0 here;
 * {@see RangedToken} carries them.
 *
 * Immutable. No enum is used so parser code can compare kinds with plain
 * `===` against TokenKind constants without tripping the current AOT
 * compiler's enum-identity bug.
 */
class Token
{
    public function __construct(
        public readonly string $kind,
        public readonly string $lexeme,
        public readonly int $line,
        public readonly int $column,
    ) {}

    public function endLine(): int
    {
        return 0;
    }

    public function endColumn(): int
    {
        return 0;
    }

    public function describe(): string
    {
        return $this->kind . ' [' . (string)strlen($this->lexeme) . ']'
            . ' (line=' . (string)$this->line . ', col=' . (string)$this->column . ')';
    }
}
