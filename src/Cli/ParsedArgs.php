<?php

namespace Cli;

/**
 * Result of {@see ArgParse::parse}: the recognized flags, option values and the
 * leftover positional arguments (files), plus a parse error if any.
 */
final class ParsedArgs
{
    /**
     * @param array<string, bool>   $flags       flag name → present
     * @param array<string, string> $values      value-option name → value
     * @param string[]              $positional  non-option arguments, in order
     */
    public function __construct(
        public array $flags,
        public array $values,
        public array $positional,
        public ?string $error,
    ) {}

    public function flag(string $name): bool
    {
        return isset($this->flags[$name]);
    }

    public function value(string $name, string $default): string
    {
        return $this->values[$name] ?? $default;
    }

    public function has(string $name): bool
    {
        return isset($this->values[$name]);
    }
}
