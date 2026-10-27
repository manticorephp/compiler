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
        public readonly bool $globalFallback,
        public readonly bool $isDecl = false,
        public readonly string $role = '',
        public readonly string $resolved = '',
    ) {}

    /** The symbol kind this entry names: a `use` import names its imported kind (class | function | constant). */
    public function denotes(): string
    {
        return $this->kind === 'use' && $this->role !== '' ? $this->role : $this->kind;
    }
}
