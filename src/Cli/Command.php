<?php

namespace Cli;

/**
 * One subcommand of the CLI. Owns a name, a one-line description, and
 * a handler closure. Fluent API mirrors clap: `command(...)->run(fn)`.
 */
final class Command
{
    /** @var \Closure(array<int, string>): int | null */
    private ?\Closure $handler = null;

    public function __construct(
        public readonly string $name,
        public readonly string $description,
    ) {}

    /**
     * Attach the handler closure. It receives the tail of argv after
     * the command name and returns an exit code.
     */
    public function run(\Closure $handler): self
    {
        $this->handler = $handler;
        return $this;
    }

    /** @return \Closure(array<int, string>): int | null */
    public function handler(): ?\Closure
    {
        return $this->handler;
    }
}
