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
function walk_lazy(mixed $v, string $file, array &$seen, int &$count): void {
    if (is_object($v)) {
        $id = spl_object_id($v);
        if (isset($seen[$id])) { return; }
        $seen[$id] = true;
        $cn = get_class($v);
        if ($v instanceof \Parser\Ast\MethodDecl || $v instanceof \Parser\Ast\FunctionDecl) {
            if ($v->lazyBody !== null) {
                $lazy = $v->lazyBody;
                $count++;
                try {
                    $v->body = \Parser\Parser::materializeLazyBody($lazy);
                    $v->body = null;
                    $v->lazyBody = null;
                } catch (Throwable $e) {
                    $name = property_exists($v, 'name') ? (string)$v->name : $cn;
                    printf("FAIL file=%s decl=%s line=%d start=%d end=%d: %s\n", $file, $name, $lazy->line, $lazy->start, $lazy->end, $e->getMessage());
                    throw $e;
                }
            }
        }
        foreach (get_object_vars($v) as $p) { walk_lazy($p, $file, $seen, $count); }
        return;
    }
    if (is_array($v)) { foreach ($v as $p) { walk_lazy($p, $file, $seen, $count); } }
}
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
$count = 0; $parsed = 0;
foreach ($files as $path) {
    $source = file_get_contents($path);
    if ($source === false) { continue; }
    try {
        $program = \Parser\Parser::parseSource($source, $path, true);
        $seen = [];
        walk_lazy($program, $path, $seen, $count);
        $parsed++;
    } catch (Throwable $e) {
        printf("PARSE_OR_MATERIALIZE_FAIL file=%s: %s\n", $path, $e->getMessage());
        exit(1);
    }
}
printf("SUMMARY files=%d parsed=%d materialized=%d\n", count($files), $parsed, $count);
