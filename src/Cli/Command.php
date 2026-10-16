<?php

namespace Cli;

/** One subcommand: identity, declared options, and the body. Registered with {@see Cli::add()}. */
abstract class Command
{
    abstract public function name(): string;

    abstract public function summary(): string;

    /** Positional part of the usage line. */
    public function usage(): string
    {
        return '[options]';
    }

    /** @return Option[] */
    public function options(): array
    {
        return [];
    }

    abstract public function run(ParsedArgs $args): int;
}
