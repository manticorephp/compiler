<?php

namespace Semantic;

final class DocumentSymbol
{
    /**
     * @param string $kind class | interface | trait | enum | function | method | property | constant | case
     * @param DocumentSymbol[] $children
     */
    public function __construct(
        public readonly string $name,
        public readonly string $kind,
        public readonly Location $range,
        public readonly Location $nameRange,
        public readonly array $children = [],
    ) {}
}
