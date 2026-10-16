<?php

namespace Cli\Commands;

use Cli\ArgParse;
use Cli\Command;
use Cli\Option;
use Cli\ParsedArgs;

final class VersionCommand extends Command
{
    public function name(): string { return 'version'; }

    public function summary(): string { return 'Print compiler version'; }

    public function usage(): string { return '[--check]'; }

    /** @return Option[] */
    public function options(): array
    {
        return [
            new Option('check', ArgParse::FLAG, 'Report installed and latest version (same as upgrade --check); exit 1 if an update exists, 2 on error'),
        ];
    }

    public function run(ParsedArgs $args): int
    {
        if ($args->flag('check')) {
            return \Cli\Install::check('version', '');
        }
        \Manticore\puts("manticore " . \Manticore\Version::STRING);
        return 0;
    }
}
