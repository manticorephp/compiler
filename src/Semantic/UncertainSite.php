<?php

namespace Semantic;

/** reason: string-class-name | dynamic-member | callable-string | docblock | heredoc-interpolation | unresolved-receiver */
final class UncertainSite
{
    public function __construct(
        public readonly string $file,
        public readonly int $line,
        public readonly int $col,
        public readonly string $reason,
    ) {}
}
