<?php

namespace Parser\Ast;

/**
 * Source position. 1-based line and column of the first token in a node.
 *
 * $file is the path the tokens came from, and it is here rather than on the
 * nodes because the parser is the LAST stage that still knows it: lowering sees
 * one statement list flattened across every file of the build (which is also
 * why `__FILE__`/`__DIR__` fold at parse time). '' when the source has no file
 * of its own — stdin, the prelude blob, a synthesized node.
 */
final class Span
{
    public function __construct(
        public readonly int $line,
        public readonly int $column,
        public readonly string $file = '',
    ) {}
}
