<?php
/**
 * gen_manifest.php — emit the manticore.json for one tier of the ladder.
 *
 * A tier is expressed the way the builder actually models a multi-directory
 * source set: `"composer": true`, which pulls the project's own autoload roots
 * plus every package in composer.lock (Main.php:1166 composer_source_dirs), and
 * then `exclude` to CUT the tiers above N back out. There is exactly one `src`
 * per application, so listing directories individually is not an option, and
 * two applications in one manifest would each write `<prefix>_stubs.c` and
 * clobber each other.
 *
 * The manifest is written INTO the app directory because that is where
 * composer.json / composer.lock live and where the builder looks for them.
 *
 * Everything is one whole-program application: a compiled library's `.sig`
 * carries functions only, so classes, interfaces, traits, enums and constants
 * cannot cross a library boundary.
 *
 * Usage: php tools/audit/gen_manifest.php <N> --app <dir> [--out <path>]
 */

$tier = $argv[1] ?? '';
$app = '/Users/taras/var/projects/symfony-demo-probe/app';
$out = '';
for ($i = 2; $i < count($argv); $i++) {
    if ($argv[$i] === '--app' && isset($argv[$i + 1])) { $app = $argv[++$i]; }
    elseif ($argv[$i] === '--out' && isset($argv[$i + 1])) { $out = $argv[++$i]; }
}
if ($tier === '') {
    fwrite(STDERR, "gen_manifest: usage: gen_manifest.php <N> --app <dir>\n");
    exit(2);
}
$tierKey = str_starts_with($tier, 'T') ? $tier : 'T' . $tier;
if ($out === '') { $out = $app . '/manticore.' . strtolower($tierKey) . '.json'; }

$j = json_decode((string)@file_get_contents('docs/audit/tiers.json'), true);
if (!isset($j['tiers'][$tierKey])) {
    fwrite(STDERR, "gen_manifest: unknown tier $tierKey — run gen_tiers.php first\n");
    exit(2);
}

$below = [];
$exclude = [];
$seenTier = false;
foreach ($j['tiers'] as $name => $info) {
    if ($seenTier) {
        // A tier ABOVE the one under test: cut every one of its packages out of
        // the composer-derived source set.
        foreach ($info['dirs'] as $d) { $exclude['./' . $d] = true; }
        continue;
    }
    foreach ($info['packages'] as $p) { $below[] = $p; }
    foreach ($info['exclude'] as $e) { $exclude[$e] = true; }
    if ($name === $tierKey) { $seenTier = true; }
}

// Packages the FRONT END cannot parse yet. Excluded so the rest of the tier can
// still be measured — a build that dies on file one measures nothing at all.
//
// This is a declared, reproducible skip, not a hand-edit of a generated file,
// and every entry names the finding that owns it. `run_tier.sh` prints the list
// on every run: the audit's rule is that a bounded measurement says so out loud,
// because a silent skip reads exactly like a clean result.
$parseBlocked = [
    // `[&$refs[$k], $value, &$value]` — a reference inside an array literal.
    // finding: parser-ref-in-array-literal
    './vendor/symfony/polyfill-deepclone' => 'parser-ref-in-array-literal',
];
foreach ($parseBlocked as $d => $finding) { $exclude[$d] = true; }
fwrite(STDERR, "gen_manifest: parse-blocked skips: "
    . implode(', ', array_map(
        static fn ($d, $f) => $d . ' (' . $f . ')',
        array_keys($parseBlocked), array_values($parseBlocked))) . "\n");

// The application's own code is T8. Below that, exclude it too, or every tier
// would drag in the whole app and stop being a tier.
if ($tierKey !== 'T8') {
    // `./var` holds the warmed cache — the dumped DI container and compiled
    // templates. Those are the APPLICATION's build artifacts (T8) and reference
    // every tier above the one under test, so leaving them in put the whole app
    // back into every tier and made T1 fail on a T4 construct.
    foreach (['./src', './config', './templates', './tests', './migrations', './var'] as $d) {
        $exclude[$d] = true;
    }
}

/**
 * The first UNCONDITIONALLY declared class/interface/trait/enum in a package,
 * fully qualified, or '' when the package declares none.
 *
 * Column-0 only, so a stub declared inside `if (PHP_VERSION_ID < …)` is never
 * picked — on a current target that guard folds false and the class does not
 * exist. Test and stub directories are skipped for the same reason composer
 * excludes them from its classmap.
 */
function audit_first_class_of(string $pkgDir): string
{
    if (!is_dir($pkgDir)) { return ''; }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pkgDir));
    $files = [];
    foreach ($it as $f) {
        if (!$f->isFile() || $f->getExtension() !== 'php') { continue; }
        $path = $f->getPathname();
        if (preg_match('#/(Test|Tests|Resources/stubs)/#', $path)) { continue; }
        $files[] = $path;
    }
    sort($files);
    foreach ($files as $path) {
        $src = file_get_contents($path);
        if ($src === false) { continue; }
        if (!preg_match('/^(?:final\s+|abstract\s+|readonly\s+)*(?:class|interface|trait|enum)\s+(\w+)/m', $src, $m)) {
            continue;
        }
        $ns = preg_match('/^namespace\s+([^;{\s]+)/m', $src, $nm) ? $nm[1] . '\\' : '';
        return $ns . $m[1];
    }
    return '';
}

// A smoke entry that merely NAMES the tier is not enough: with nothing
// reachable the whole tier is dead-code-eliminated, the stub list comes back
// empty, and the tier reads as complete. Touching each package's classes
// through class_exists keeps their metadata live without needing a constructor
// that works.
@mkdir($app . '/tiers', 0777, true);
$entryRel = 'tiers/' . strtolower($tierKey) . '_smoke.php';

$lines = [
    '<?php', '',
    '// GENERATED by tools/audit/gen_manifest.php — do not edit.',
    '// Keeps every package of tiers <= ' . $tierKey . ' reachable, so dead-code',
    '// elimination cannot empty the stub list and make the tier look complete.',
    '',
    '$live = 0;',
];
$named = 0;
foreach ($below as $p) {
    // A REAL declared class per package, read out of its sources. This used to
    // guess `Vendor\Package` from the directory name, and every guess was
    // wrong — the entry printed "0 root classes live", which is not a harmless
    // cosmetic zero: a class_exists on a name that exists nowhere folds to
    // false at compile time, so the entry referenced nothing and could not do
    // the one job it has. The count IS the evidence that the tier is reachable,
    // so it has to be able to be non-zero.
    $fqcn = audit_first_class_of(rtrim($app, '/') . '/vendor/' . $p);
    if ($fqcn === '') { continue; }
    $lines[] = '$live += (int)class_exists(' . var_export($fqcn, true) . ');';
    $named++;
}
$lines[] = 'echo "tier ' . $tierKey . ': ", $live, "/' . $named . ' root classes live\n";';
file_put_contents($app . '/' . $entryRel, implode("\n", $lines) . "\n");

$manifest = [
    'applications' => [[
        'name' => 'audit_' . strtolower($tierKey),
        'src' => 'tiers',
        'output' => 'audit-' . strtolower($tierKey) . '_bin',
        'entry' => $entryRel,
        'composer' => true,
        'extensions' => [],
        'exclude' => array_keys($exclude),
    ]],
];

file_put_contents($out, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
printf("gen_manifest: %s -> %s (%d packages in scope, %d excludes)\n",
    $tierKey, $out, count($below), count($exclude));
