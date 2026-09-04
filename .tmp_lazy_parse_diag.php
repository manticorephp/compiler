<?php
$root = '/Users/taras/var/projects/symfony-demo-probe/app';
$srcRoot = '/Users/taras/var/projects/manticore-audit/src';
spl_autoload_register(function (string $class) use ($srcRoot): void {
    $file = $srcRoot . '/' . str_replace('\\', '/', $class) . '.php';
    if (is_file($file)) { require_once $file; }
});
$exclude = [
    '/vendor/symfony/console/Tester', '/vendor/symfony/console/DataCollector',
    '/vendor/symfony/console/Debug', '/vendor/composer/semver',
    '/vendor/egulias/email-validator', '/vendor/league/uri',
    '/vendor/league/uri-interfaces', '/vendor/monolog/monolog',
    '/vendor/symfony/asset-mapper', '/vendor/symfony/form',
    '/vendor/symfony/html-sanitizer', '/vendor/symfony/http-client',
    '/vendor/symfony/mailer', '/vendor/symfony/mime',
    '/vendor/symfony/monolog-bridge', '/vendor/symfony/monolog-bundle',
    '/vendor/symfony/password-hasher', '/vendor/symfony/property-access',
    '/vendor/symfony/property-info', '/vendor/symfony/security-bundle',
    '/vendor/symfony/security-core', '/vendor/symfony/security-csrf',
    '/vendor/symfony/security-http', '/vendor/symfony/stimulus-bundle',
    '/vendor/symfony/ux-icons', '/vendor/symfony/ux-live-component',
    '/vendor/symfony/ux-twig-component', '/vendor/symfony/validator',
    '/vendor/symfonycasts/sass-bundle', '/vendor/twbs/bootstrap',
    '/vendor/symfony/polyfill-deepclone', '/tests', '/migrations',
    '/config', '/templates', '/var', '/src',
];
$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (!$f->isFile() || strtolower($f->getExtension()) !== 'php') { continue; }
    $path = str_replace('\\', '/', $f->getPathname());
    $rel = substr($path, strlen($root));
    $skip = false;
    foreach ($exclude as $part) { if (str_starts_with($rel, $part . '/') || $rel === $part) { $skip = true; break; } }
    if (!$skip) { $files[] = $path; }
}
sort($files);
$ok = 0; $fail = 0;
foreach ($files as $path) {
    $source = file_get_contents($path);
    if ($source === false) { continue; }
    try {
        \Parser\Parser::parseSource($source, $path, true);
        $ok++;
    } catch (Throwable $e) {
        $fail++;
        printf("FAIL %s: %s\n", $path, $e->getMessage());
    }
}
printf("SUMMARY files=%d ok=%d fail=%d\n", count($files), $ok, $fail);
exit($fail === 0 ? 0 : 1);
