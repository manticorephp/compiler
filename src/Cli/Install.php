<?php

namespace Cli;

/** What the CLI knows about how this compiler was installed (install.sh layout) and what the latest release is. */
final class Install
{
    /** The install prefix (parent of bin/), '' when the running binary's location is unknown. */
    public static function home(): string
    {
        $selfDir = \Manticore\self_dir();
        return $selfDir === '' ? '' : \dirname($selfDir);
    }

    /** Path of the installed install.sh, '' when this compiler was not put there by install.sh. */
    public static function installer(): string
    {
        $home = self::home();
        $installer = $home . '/share/install.sh';
        if ($home === '' || !\file_exists($installer)) {
            return '';
        }
        return $installer;
    }

    public static function owner(string $dir): string
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

    /** True (after a message on stderr) when install.sh did not install this compiler. */
    public static function foreign(string $who): bool
    {
        if (self::installer() !== '') {
            return false;
        }
        \Manticore\dprint($who . ': this compiler was not installed by install.sh (' . self::owner(\Manticore\self_dir())
            . '); update it with that installer. Nothing was changed.');
        return true;
    }

    /** Environment prefix for install.sh invocations. */
    public static function env(string $versionArg): string
    {
        $env = 'MANTICORE_HOME=' . \escapeshellarg(self::home());
        if ($versionArg !== '') {
            $env = $env . ' MANTICORE_VERSION=' . \escapeshellarg($versionArg);
        }
        return $env;
    }

    /** Asks the installed install.sh for the target version; '' (after a message unless $quiet) on failure. */
    public static function latest(string $who, string $versionArg, bool $quiet = false): string
    {
        $out = \shell_exec(self::env($versionArg) . ' bash ' . \escapeshellarg(self::installer()) . ' --check 2>&1');
        $text = \is_string($out) ? $out : '';
        if (\preg_match('/^latest=(\S+)\s*$/m', $text, $m) === 1) {
            return $m[1];
        }
        if (!$quiet) {
            \Manticore\dprint($who . ': cannot determine the latest release' . ($text !== '' ? ': ' . \trim($text) : ''));
        }
        return '';
    }

    /** Prints installed vs latest; 0 current, 1 update available, 2 not installable / latest unknown. */
    public static function check(string $who, string $versionArg): int
    {
        if (self::foreign($who)) {
            return 2;
        }
        $latest = self::latest($who, $versionArg);
        if ($latest === '') {
            return 2;
        }
        $installed = \Manticore\Version::STRING;
        $cmp = \version_compare($installed, $latest);
        \Manticore\puts('installed ' . $installed . ' / latest ' . $latest . ($cmp < 0 ? ' — update available' : ($cmp === 0 ? ' — up to date' : ' — installed is newer')));
        return $cmp < 0 ? 1 : 0;
    }
}
