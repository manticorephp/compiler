<?php

namespace Parser\Ast;

/**
 * A {@see Span} with a range: (toLine, toColumn) is just past the node's last
 * byte; (fromLine, fromColumn) is its first byte when that differs from the
 * anchor (0 = same as line/column). Only a parser run with ranges makes these:
 * the compiler's own (strict) path keeps the three-field Span.
 */
class RangedSpan extends Span
{
    public function __construct(
        int $line,
        int $column,
        string $file,
        public readonly int $toLine,
        public readonly int $toColumn,
        public readonly int $fromLine = 0,
        public readonly int $fromColumn = 0,
    ) {
        parent::__construct($line, $column, $file);
    }

    public function startLine(): int
    {
        return $this->fromLine !== 0 ? $this->fromLine : $this->line;
    }

    public function startColumn(): int
    {
        return $this->fromLine !== 0 ? $this->fromColumn : $this->column;
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
