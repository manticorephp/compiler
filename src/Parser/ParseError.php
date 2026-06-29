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

    public function __construct(string $message, int $line, int $column)
    {
        parent::__construct(
            $message . ' at line ' . (string)$line . ', column ' . (string)$column
        );
        $this->errLine = $line;
        $this->column = $column;
    }
}
