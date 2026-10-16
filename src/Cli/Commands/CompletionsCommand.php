<?php

namespace Cli\Commands;

use Cli\Cli;
use Cli\Command;
use Cli\Completion;
use Cli\ParsedArgs;

final class CompletionsCommand extends Command
{
    public function __construct(private Cli $cli) {}

    public function name(): string { return 'completions'; }

    public function summary(): string { return 'Print a shell completion script (bash, zsh or fish)'; }

    public function usage(): string { return '<bash|zsh|fish>'; }

    public function run(ParsedArgs $args): int
    {
        $sh = $args->positional[0] ?? '';
        if ($sh === 'bash') {
            \Manticore\puts(\rtrim(Completion::bash($this->cli), "\n"));
            return 0;
        }
        if ($sh === 'zsh') {
            \Manticore\puts(\rtrim(Completion::zsh($this->cli), "\n"));
            return 0;
        }
        if ($sh === 'fish') {
            \Manticore\puts(\rtrim(Completion::fish($this->cli), "\n"));
            return 0;
        }
        \Manticore\dprint('completions: expected one of bash, zsh, fish');
        return 64;
    }
}
