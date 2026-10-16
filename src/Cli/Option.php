<?php

namespace Cli;

/** One declared command option; {@see Command::options()}. Single-letter names are short options (`-o`). */
final class Option
{
    public function __construct(
        public readonly string $name,
        public readonly string $kind,
        public readonly string $help,
        public readonly string $placeholder = '',
    ) {}
}
