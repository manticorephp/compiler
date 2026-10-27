<?php

namespace Semantic;

final class NameOccurrence
{
    public function __construct(
        public readonly string $file,
        public readonly string $kind,
        public readonly string $resolvedLower,
        public readonly int $line,
        public readonly int $col,
        public readonly int $endLine,
        public readonly int $endCol,
    ) {}
}
