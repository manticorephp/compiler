<?php

namespace Parser;

use Parser\Ast\Program;

/** Outcome of {@see Parser::parseTolerant()}: a tree plus every syntax error found. */
final class ParseResult
{
    /** @param ParseError[] $errors */
    public function __construct(
        public readonly Program $program,
        public readonly array $errors,
    ) {}
}
