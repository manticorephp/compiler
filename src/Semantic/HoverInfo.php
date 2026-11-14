<?php

namespace Semantic;

final class HoverInfo
{
    public function __construct(
        public readonly string $text,
        public readonly Location $range,
        /** markdown shown under the code block: array keys and values, source label */
        public readonly string $detail = '',
    ) {}
}
