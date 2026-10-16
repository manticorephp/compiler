<?php

namespace Composer;

/**
 * Stand-in for Composer's runtime API inside a compiled binary: there is no vendor/composer/installed.php
 * to `include` at run time, and every package the program uses is compiled in, so a package counts as
 * installed (as a non-dev requirement) exactly when one of its classes is present.
 */
final class InstalledVersions
{
    public static function isInstalled(string $packageName, bool $includeDevRequirements = true): bool
    {
        return $packageName !== 'symfony/symfony';
    }

    /**
     * @return array{name: string, pretty_version: string, version: string, reference: string|null, type: string, install_path: string, aliases: string[], dev: bool}
     */
    public static function getRootPackage(): array
    {
        return [
            'name' => 'app/symfony-app',
            'pretty_version' => 'dev-main',
            'version' => 'dev-main',
            'reference' => null,
            'type' => 'project',
            'install_path' => '',
            'aliases' => [],
            'dev' => true,
        ];
    }

    public static function getVersion(string $packageName): ?string
    {
        return null;
    }

    public static function getPrettyVersion(string $packageName): ?string
    {
        return null;
    }
}
