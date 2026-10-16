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
        $selfDir = \Manticore\self_dir();
        $home = $selfDir === '' ? '' : \dirname($selfDir);
        $installer = $home . '/share/install.sh';
        if ($home === '' || !\file_exists($installer)) {
            \Manticore\dprint('upgrade: this compiler was not installed by install.sh (' . $this->owner($selfDir)
                . '); update it with that installer. Nothing was changed.');
            return 2;
        }
        $installed = \Manticore\Version::STRING;
        $want = $args->value('version', '');
        $env = 'MANTICORE_HOME=' . \escapeshellarg($home);
        if ($want !== '') {
            $env = $env . ' MANTICORE_VERSION=' . \escapeshellarg($want);
        }
        $latest = $this->latest($env, $installer);
        if ($latest === '') {
            return 2;
        }
        $cmp = \version_compare($installed, $latest);
        if ($args->flag('check')) {
            \Manticore\puts('installed ' . $installed . ' / latest ' . $latest . ($cmp < 0 ? ' — update available' : ($cmp === 0 ? ' — up to date' : ' — installed is newer')));
            return $cmp < 0 ? 1 : 0;
        }
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
        \system($env . ' MANTICORE_STRICT=1 bash ' . \escapeshellarg($installer), $rc);
        return $rc;
    }

    /** Asks the installed install.sh for the target version; '' (after a message) on failure. */
    private function latest(string $env, string $installer): string
    {
        $out = \shell_exec($env . ' bash ' . \escapeshellarg($installer) . ' --check 2>&1');
        $text = \is_string($out) ? $out : '';
        if (\preg_match('/^latest=(\S+)\s*$/m', $text, $m) === 1) {
            return $m[1];
        }
        \Manticore\dprint('upgrade: cannot determine the latest release' . ($text !== '' ? ': ' . \trim($text) : ''));
        return '';
    }

    private function owner(string $dir): string
    {
        if (\str_contains($dir, '/Cellar/') || \str_starts_with($dir, '/opt/homebrew')) {
            return 'Homebrew; try `brew upgrade manticore`';
        }
        if (\str_starts_with($dir, '/usr/bin') || \str_starts_with($dir, '/usr/lib')) {
            return 'a system package manager';
        }
        if (\str_starts_with($dir, '/opt/manticore')) {
            return 'the container image; pull a newer image';
        }
        return 'an unknown installer';
    }
}
