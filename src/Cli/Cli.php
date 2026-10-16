<?php

namespace Cli;

/**
 * Minimal Rust-clap-flavoured CLI registry. Commands are classes registered
 * with {@see add()}; their declared options drive parsing and generated help.
 * The closure-based {@see LegacyCommand} registry stays until every command
 * is migrated. No command given, or an unknown one, prints `help`.
 */
final class Cli
{
    /** @var array<string, LegacyCommand> */
    private array $commands = [];

    /** @var array<string, Command> */
    private array $registry = [];

    public function __construct(
        public readonly string $name,
        public readonly string $description,
    ) {}

    public function add(Command $cmd): void
    {
        $this->registry[$cmd->name()] = $cmd;
    }

    public function command(string $name, string $description): LegacyCommand
    {
        $cmd = new LegacyCommand($name, $description);
        $this->commands[$name] = $cmd;
        return $cmd;
    }

    /** @return array<string, LegacyCommand> */
    public function commands(): array
    {
        return $this->commands;
    }

    /** @return array<string, string> */
    private function specOf(Command $cmd): array
    {
        /** @var array<string, string> $spec */
        $spec = [];
        foreach ($cmd->options() as $o) {
            $spec[$o->name] = $o->kind;
        }
        if (!isset($spec['help'])) {
            $spec['help'] = ArgParse::FLAG;
        }
        if (!isset($spec['h'])) {
            $spec['h'] = ArgParse::FLAG;
        }
        return $spec;
    }

    /** @param string[] $tail */
    private function runCommand(Command $cmd, array $tail): int
    {
        $p = ArgParse::parse($tail, $this->specOf($cmd));
        if ($p->error !== null) {
            \Manticore\dprint($cmd->name() . ': ' . $p->error);
            return 64;
        }
        // Detected on the PARSED result: `compile -o --help` makes --help a value.
        if ($p->flag('help') || $p->flag('h')) {
            return $this->runCommandHelp($cmd);
        }
        return $cmd->run($p);
    }

    private function runCommandHelp(Command $cmd): int
    {
        \Manticore\puts($cmd->name() . ' — ' . $cmd->summary());
        \Manticore\puts('');
        \Manticore\puts(\rtrim('Usage: ' . $this->name . ' ' . $cmd->name() . ' ' . $cmd->usage()));
        $opts = $cmd->options();
        if (\count($opts) > 0) {
            \Manticore\puts('');
            \Manticore\puts('Options:');
            /** @var string[] $left */
            $left = [];
            $width = 0;
            foreach ($opts as $o) {
                $s = (\strlen($o->name) === 1 ? '-' : '--') . $o->name;
                if ($o->kind === ArgParse::VALUE) {
                    $s = $s . ' <' . ($o->placeholder !== '' ? $o->placeholder : 'value') . '>';
                }
                $left[] = $s;
                if (\strlen($s) > $width) {
                    $width = \strlen($s);
                }
            }
            foreach ($opts as $i => $o) {
                \Manticore\puts('  ' . \str_pad($left[$i], $width) . '  ' . $o->help);
            }
        }
        return 0;
    }

    /**
     * Run the command named by `argv[1]`. Returns its exit code. Falls back
     * to `help` when no command was provided or the name is unknown.
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
        if ($name === '--help' || $name === '-h') {
            return $this->runHelp();
        }
        if ($name === '--version') {
            $name = 'version';
        }
        /** @var string[] $tail */
        $tail = [];
        for ($i = 2; $i < $argc; $i = $i + 1) {
            $tail[] = $argv[$i];
        }
        if (isset($this->registry[$name])) {
            return $this->runCommand($this->registry[$name], $tail);
        }
        if (!isset($this->commands[$name])) {
            \Manticore\dprint("unknown command: " . $name);
            return $this->runHelp();
        }
        $handler = $this->commands[$name]->handler();
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
        foreach ($this->registry as $name => $cmd) {
            \Manticore\puts("  " . $name . "  " . $cmd->summary());
        }
        foreach ($this->commands as $name => $cmd) {
            \Manticore\puts("  " . $name . "  " . $cmd->description);
        }
        \Manticore\puts("");
        \Manticore\puts("Run '" . $this->name . " <command> --help' for command options.");
        return 0;
    }
}
