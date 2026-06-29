<?php

namespace Cli;

/**
 * Minimal Rust-clap-flavoured CLI registry. Owns a set of named
 * subcommands; each command carries a one-line description, a handler
 * closure that receives the post-command argv tail, and an optional
 * usage hint printed by `help`. Dispatch parses `argv[1]` to pick the
 * command, defaults to `help` when no command is given or the name is
 * unknown.
 */
final class Cli
{
    /** @var array<string, Command> */
    private array $commands = [];

    public function __construct(
        public readonly string $name,
        public readonly string $description,
    ) {}

    public function command(string $name, string $description): Command
    {
        $cmd = new Command($name, $description);
        $this->commands[$name] = $cmd;
        return $cmd;
    }

    /**
     * Walk the registered commands so callers (e.g. `help`) can
     * enumerate them without poking private state. Returns the inner
     * array directly — the caller's expected to read-only.
     *
     * @return array<string, Command>
     */
    public function commands(): array
    {
        return $this->commands;
    }

    /**
     * Run the command named by `argv[1]`. Returns the exit code from
     * the handler, or 2 when the requested command is missing. Falls
     * back to `help` when no command was provided.
     *
     * @param string[] $argv
     */
    public function run(array $argv): int
    {
        $argc = \count($argv);
        if ($argc < 2) {
            return $this->runHelp();
        }
        $name = $argv[1];
        if (!isset($this->commands[$name])) {
            \Manticore\dprint("unknown command: " . $name);
            return $this->runHelp();
        }
        $cmd = $this->commands[$name];
        $tail = [];
        for ($i = 2; $i < $argc; $i = $i + 1) {
            $tail[] = $argv[$i];
        }
        $handler = $cmd->handler();
        if ($handler === null) {
            \Manticore\dprint("command not wired: " . $name);
            return 70;
        }
        return $handler($tail);
    }

    public function runHelp(): int
    {
        \Manticore\puts($this->name . " — " . $this->description);
        \Manticore\puts("");
        \Manticore\puts("Usage: " . $this->name . " <command> [options...]");
        \Manticore\puts("");
        \Manticore\puts("Commands:");
        foreach ($this->commands as $name => $cmd) {
            \Manticore\puts("  " . $name . "  " . $cmd->description);
        }
        return 0;
    }
}
