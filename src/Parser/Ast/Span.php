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
 * Range: ($endLine, $endColumn) is just past the node's last byte (0 = not
 * set). ($beginLine, $beginColumn) is the first byte of the range when it
 * differs from the anchor (0 = same as line/column); use {@see startLine()} /
 * {@see startColumn()}. Columns are bytes.
 */
final class Span
{
    public function __construct(
        public readonly int $line,
        public readonly int $column,
        public readonly string $file = '',
        public readonly int $endLine = 0,
        public readonly int $endColumn = 0,
        public readonly int $beginLine = 0,
        public readonly int $beginColumn = 0,
    ) {}

    public function startLine(): int
    {
        return $this->beginLine !== 0 ? $this->beginLine : $this->line;
    }

    public function startColumn(): int
    {
        return $this->beginLine !== 0 ? $this->beginColumn : $this->column;
    }
}
