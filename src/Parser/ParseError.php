<?php

namespace Parser;

/**
 * Thrown by Parser on unrecoverable syntax errors.
 *
 * Properties are NOT marked readonly because `\Exception` already exposes
 * mutable `$line` and `$file` members; redeclaring them readonly is a
 * compatibility error.
 */
final class ParseError extends \RuntimeException
{
    public int $column;
    public int $errLine;
    public int $endLine;
    public int $endColumn;
    public string $reason;

    public function __construct(string $message, int $line, int $column, int $endLine = 0, int $endColumn = 0, string $reason = '')
    {
        parent::__construct(
            $message . ' at line ' . (string)$line . ', column ' . (string)$column
        );
        $this->errLine = $line;
        $this->column = $column;
        $this->endLine = $endLine;
        $this->endColumn = $endColumn;
        $this->reason = $reason === '' ? $message : $reason;
    }
}
