<?php

namespace Analyze;

use Parser\Ast\Program;

/** A successfully-parsed input: its path paired with its AST. */
final class ParsedFile
{
    public function __construct(
        public string $path,
        public Program $program,
    ) {}
}
