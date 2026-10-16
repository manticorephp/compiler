<?php

namespace Cli\Commands;

use Cli\Command;
use Cli\ParsedArgs;

final class VersionCommand extends Command
{
    public function name(): string { return 'version'; }

    public function summary(): string { return 'Print compiler version'; }

    public function usage(): string { return ''; }

    public function run(ParsedArgs $args): int
    {
        \Manticore\puts("manticore 0.14.0");
        return 0;
    }
}
