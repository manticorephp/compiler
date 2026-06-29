<?php

namespace Manticore\Attr;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class Project
{
    public function __construct(public readonly string $name) {}
}
