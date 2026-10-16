<?php

namespace Cli\Commands;

use Cli\ArgParse;
use Cli\Command;
use Cli\Option;
use Cli\ParsedArgs;

final class UpgradeCommand extends Command
{
    public function name(): string { return 'upgrade'; }

    public function summary(): string
    {
        return 'Update the installed compiler to the latest release (--check only reports)';
    }

    public function usage(): string { return '[--check] [--version X]'; }

    /** @return Option[] */
    public function options(): array
    {
        return [
            new Option('check', ArgParse::FLAG, 'Report installed and latest version; change nothing. Exit 1 if an update exists, 2 on error'),
            new Option('version', ArgParse::VALUE, 'Install this release instead of the latest', 'X'),
        ];
    }

    public function run(ParsedArgs $args): int
    {
        $want = $args->value('version', '');
        if ($args->flag('check')) {
            return \Cli\Install::check('upgrade', $want);
        }
        if (\Cli\Install::foreign('upgrade')) {
            return 2;
        }
        $installed = \Manticore\Version::STRING;
        $latest = \Cli\Install::latest('upgrade', $want);
        if ($latest === '') {
            return 2;
        }
        $cmp = \version_compare($installed, $latest);
        if ($cmp === 0) {
            \Manticore\puts('manticore ' . $installed . ' is already ' . ($want !== '' ? 'the requested version' : 'up to date'));
            return 0;
        }
        if ($cmp > 0 && $want === '') {
            \Manticore\puts('manticore ' . $installed . ' is newer than the latest release ' . $latest . '; nothing to do');
            return 0;
        }
        if ($cmp > 0) {
            \Manticore\puts('downgrading ' . $installed . ' -> ' . $latest);
        }
        $rc = 0;
        \system(\Cli\Install::env($want) . ' MANTICORE_STRICT=1 bash ' . \escapeshellarg(\Cli\Install::installer()), $rc);
        return $rc;
    }
}
