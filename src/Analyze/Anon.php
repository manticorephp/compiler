<?php

namespace Analyze;

final class Anon
{
    public static function isAnon(string $name): bool
    {
        return \str_starts_with($name, '__anon_class_');
    }
}
