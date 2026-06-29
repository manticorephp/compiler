<?php

namespace Manticore\Attr;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Module
{
    public function __construct(public readonly string $path) {}
}
