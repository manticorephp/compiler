<?php

namespace Semantic;

final class HoverInfo
{
    public function __construct(
        public readonly string $text,
        public readonly Location $range,
    ) {}
}
