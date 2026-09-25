<?php
// A json_decode'd (cell) array narrowed by an inline `@var list<array{…}>`
// reaches the typed reader with raw elements — php-cs-fixer's
// ToolInfo::getComposerInstallationDetails.

final class Info
{
    /** @var null|array{name: string, version: string, dist: array{reference?: string}} */
    private ?array $details = null;

    public function details(string $json): array
    {
        if (null === $this->details) {
            $installed = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
            /** @var list<array{name: string, version: string, dist: array{reference?: string}}> $packages */
            $packages = $installed['packages'] ?? $installed;
            foreach ($packages as $package) {
                if (\in_array($package['name'], ['friendsofphp/php-cs-fixer', 'fabpot/php-cs-fixer'], true)) {
                    $this->details = $package;
                    break;
                }
            }
        }
        return $this->details;
    }
}

$json = '{"packages":[{"name":"a/b","version":"1.0","dist":{}},{"name":"friendsofphp/php-cs-fixer","version":"3.95.27","dist":{"reference":"abc"}}]}';
$d = (new Info())->details($json);
echo $d['name'], ' ', $d['version'], ' ', $d['dist']['reference'] ?? '-', "\n";
