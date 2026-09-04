<?php
/**
 * gen_tiers.php — turn composer.lock into the audit ladder.
 *
 * The tier rule is: a tier's dependency closure lies entirely in LOWER tiers.
 * That is what makes a finding at tier N attributable to tier N (nothing above
 * it is in the analyzed closed world) and what makes the ladder double as the
 * implementation order for the follow-on epics.
 *
 * Hand-assigning 99 packages would break that rule silently the first time a
 * dependency moved. So tiers are declared by ANCHOR package only, and each
 * tier's real membership is computed: anchor + every transitive dependency not
 * already claimed by a lower tier. Closure holds by construction.
 *
 * Usage: php tools/audit/gen_tiers.php <probe-app-dir> [--out tests/audit/tiers.json]
 */

$appDir = $argv[1] ?? '';
$outPath = 'tests/audit/tiers.json';
for ($i = 2; $i < count($argv); $i++) {
    if ($argv[$i] === '--out' && isset($argv[$i + 1])) { $outPath = $argv[++$i]; }
}
if ($appDir === '' || !is_file($appDir . '/composer.lock')) {
    fwrite(STDERR, "gen_tiers: usage: gen_tiers.php <dir-with-composer.lock>\n");
    exit(2);
}

$lock = json_decode((string)file_get_contents($appDir . '/composer.lock'), true);

/** @var array<string,string[]> $deps  package -> its require'd package names */
$deps = [];
foreach ($lock['packages'] as $p) {
    $name = $p['name'];
    $deps[$name] = [];
    foreach (array_keys($p['require'] ?? []) as $r) {
        // php, ext-*, composer-* and friends are not packages.
        if (!str_contains($r, '/')) { continue; }
        $deps[$name][] = $r;
    }
}

/**
 * Anchors, lowest tier first. An anchor names what the tier is ABOUT; its
 * dependencies fall into it automatically unless a lower tier already took
 * them. Order is the audit's ratchet order.
 */
$anchors = [
    'T1' => ['psr/container', 'psr/log', 'psr/cache', 'psr/clock', 'psr/event-dispatcher',
             'psr/http-message', 'psr/http-factory',
             'symfony/deprecation-contracts', 'symfony/service-contracts',
             'symfony/event-dispatcher-contracts', 'symfony/cache-contracts',
             'symfony/translation-contracts', 'symfony/http-client-contracts',
             'symfony/polyfill-mbstring', 'symfony/polyfill-intl-grapheme',
             'symfony/polyfill-intl-normalizer', 'symfony/polyfill-intl-icu',
             'symfony/polyfill-intl-idn', 'symfony/polyfill-intl-messageformatter',
             'symfony/polyfill-php85', 'symfony/polyfill-deepclone'],
    'T2' => ['symfony/string', 'symfony/var-exporter', 'symfony/var-dumper',
             'symfony/finder', 'symfony/filesystem', 'symfony/yaml',
             'symfony/options-resolver', 'symfony/expression-language',
             'symfony/type-info', 'symfony/process', 'symfony/clock'],
    'T3' => ['symfony/config', 'symfony/dependency-injection',
             'symfony/event-dispatcher', 'symfony/http-foundation', 'symfony/cache'],
    'T4' => ['symfony/routing', 'symfony/http-kernel', 'symfony/error-handler',
             'symfony/console', 'symfony/dotenv', 'symfony/runtime',
             'symfony/framework-bundle', 'symfony/flex'],
    'T5' => ['twig/twig', 'symfony/twig-bridge', 'symfony/twig-bundle',
             'symfony/asset', 'symfony/translation', 'symfony/intl',
             'twig/extra-bundle', 'twig/intl-extra', 'twig/markdown-extra',
             'league/commonmark'],
    'T6' => ['doctrine/orm', 'doctrine/dbal', 'doctrine/doctrine-bundle',
             'symfony/doctrine-bridge'],
    'T7' => ['symfony/security-bundle', 'symfony/form', 'symfony/validator',
             'symfony/mailer', 'symfony/mime', 'symfony/password-hasher',
             'symfony/property-access', 'symfony/property-info',
             'symfony/html-sanitizer', 'symfony/http-client',
             'symfony/monolog-bundle', 'monolog/monolog', 'symfony/monolog-bridge',
             'symfony/asset-mapper', 'symfony/stimulus-bundle',
             'symfony/ux-icons', 'symfony/ux-twig-component',
             'symfony/ux-live-component', 'symfonycasts/sass-bundle'],
];

/** Transitive closure of one package, over the lock's require graph. */
function closure(string $pkg, array $deps, array &$seen): void
{
    if (isset($seen[$pkg]) || !isset($deps[$pkg])) { return; }
    $seen[$pkg] = true;
    foreach ($deps[$pkg] as $d) { closure($d, $deps, $seen); }
}

$claimed = [];
$tiers = [];
foreach ($anchors as $tier => $anchorList) {
    $members = [];
    foreach ($anchorList as $a) {
        if (!isset($deps[$a])) { continue; }   // not installed in this lock
        $seen = [];
        closure($a, $deps, $seen);
        foreach (array_keys($seen) as $m) {
            if (isset($claimed[$m])) { continue; }
            $members[$m] = true;
        }
    }
    foreach (array_keys($members) as $m) { $claimed[$m] = $tier; }
    $ms = array_keys($members);
    sort($ms);
    $tiers[$tier] = $ms;
}

// Anything the anchors never reached: assets, pure-data packages. They still
// have to be SOMEWHERE or the closed world is incomplete, so they land in the
// last framework tier rather than being silently dropped.
$orphans = [];
foreach (array_keys($deps) as $p) { if (!isset($claimed[$p])) { $orphans[] = $p; } }
sort($orphans);
if ($orphans) { $tiers['T7'] = array_values(array_unique(array_merge($tiers['T7'], $orphans))); }

// T8 is the application itself, not a package.
$tiers['T8'] = [];

$out = ['generated_from' => $appDir . '/composer.lock',
        'lock_sha1' => sha1((string)file_get_contents($appDir . '/composer.lock')),
        'tiers' => []];
foreach ($tiers as $tier => $pkgs) {
    $dirs = [];
    foreach ($pkgs as $p) {
        $d = $appDir . '/vendor/' . $p;
        if (is_dir($d)) { $dirs[] = 'vendor/' . $p; }
    }
    if ($tier === 'T8') { $dirs = ['src', 'config', 'templates']; }
    $out['tiers'][$tier] = [
        'anchors' => $anchors[$tier] ?? [],
        'packages' => $pkgs,
        'dirs' => $dirs,
        // Test / dev / integration subtrees a whole-program build must not pull
        // in. `./`-prefixed, matched by str_starts_with against `find` output,
        // exactly like the existing symfony-probe manifest.
        'exclude' => ['./vendor/symfony/console/Tester',
                      './vendor/symfony/console/DataCollector',
                      './vendor/symfony/console/Debug'],
    ];
}

@mkdir(dirname($outPath), 0777, true);
file_put_contents($outPath, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

$total = 0;
foreach ($out['tiers'] as $t => $info) {
    printf("%-3s anchors=%-3d packages=%-3d dirs=%d\n",
        $t, count($info['anchors']), count($info['packages']), count($info['dirs']));
    $total += count($info['packages']);
}
printf("total %d of %d lock packages assigned -> %s\n", $total, count($deps), $outPath);
if ($total !== count($deps)) {
    fwrite(STDERR, "gen_tiers: WARNING unassigned packages remain — the closed world would be incomplete\n");
    exit(1);
}
