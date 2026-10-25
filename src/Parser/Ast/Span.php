<?php

namespace Parser\Ast;

/**
 * Source position. 1-based line and column of the node's anchor token — the
 * first token for most nodes, the OPERATOR/bracket for binary, ternary,
 * assignment and postfix nodes (that column is what `dump-ast` prints, so it
 * cannot move).
 *
 * $file is the path the tokens came from, and it is here rather than on the
 * nodes because the parser is the LAST stage that still knows it: lowering sees
 * one statement list flattened across every file of the build (which is also
 * why `__FILE__`/`__DIR__` fold at parse time). '' when the source has no file
 * of its own — stdin, the prelude blob, a synthesized node.
 *
 * Range: {@see startLine()}/{@see startColumn()} is the first byte of the
 * node, {@see endLine()}/{@see endColumn()} just past its last (0 = not set).
 * This class has none of it (start = anchor, end = 0); {@see RangedSpan}
 * carries it. Columns are bytes.
 */
class Span
{
    public function __construct(
        public readonly int $line,
        public readonly int $column,
        public readonly string $file = '',
    ) {}

    public function startLine(): int
    {
        return $this->line;
    }

    public function startColumn(): int
    {
        return $this->column;
    }

    public function endLine(): int
    {
        return 0;
    }

    public function endColumn(): int
    {
        return 0;
    }
}
