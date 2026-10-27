<?php

namespace Semantic;

final class Location
{
    public function __construct(
        public readonly string $file,
        public readonly int $line,
        public readonly int $col,
        public readonly int $endLine,
        public readonly int $endCol,
    ) {}
}
