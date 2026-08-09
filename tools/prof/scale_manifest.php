<?php
/**
 * Write a manifest that builds the compiler application from every Nth source
 * file of `src/`, so peak RSS can be plotted against input size.
 *
 *   php tools/prof/scale_manifest.php <stride> <manifest.json> <output-binary>
 *
 * The kept set is a UNIFORM SAMPLE (index % stride === 0), not a prefix: the
 * tree is wildly non-uniform (one `EmitLlvm*` trait outweighs a whole
 * namespace) and a prefix would measure the alphabet, not the size.
 *
 * The dropped files' classes stay referenced by the kept ones. That is fine and
 * deliberate — an unresolved name compiles into a runtime trap, not a build
 * error (`bin/build`'s bootstrap-gap check is what turns traps into a failure,
 * and this never runs that). The produced binary is garbage by construction;
 * only the COMPILE is being measured.
 *
 * Prints `<kept> <total> <bytes>` on stdout.
 */

$stride = isset($argv[1]) ? (int)$argv[1] : 1;
$manifestPath = isset($argv[2]) ? $argv[2] : '';
$binPath = isset($argv[3]) ? $argv[3] : '';
if ($stride < 1 || $manifestPath === '' || $binPath === '') {
    fwrite(STDERR, "usage: scale_manifest.php <stride> <manifest.json> <output-binary>\n");
    exit(2);
}

$entry = 'src/zzz_entry.php';

/** @var string[] $files */
$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('src', FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $p = $f->getPathname();
    if (substr($p, -4) !== '.php') { continue; }
    if ($p === $entry) { continue; }
    $files[] = $p;
}
sort($files);

/** @var string[] $drop */
$drop = [];
$keptBytes = filesize($entry);
$kept = 1;
foreach ($files as $i => $p) {
    if ($i % $stride === 0) {
        $kept++;
        $keptBytes += filesize($p);
        continue;
    }
    $drop[] = $p;
}

$manifest = [
    'libraries' => [],
    'applications' => [[
        'name' => 'compiler',
        'src' => 'src',
        'output' => $binPath,
        'entry' => $entry,
        'stdlib' => false,
        'exclude' => $drop,
    ]],
];
file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
echo $kept . ' ' . (count($files) + 1) . ' ' . $keptBytes . "\n";
