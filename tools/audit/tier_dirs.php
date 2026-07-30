<?php
/**
 * tier_dirs.php — print the CUMULATIVE source directories for tier N.
 *
 * Cumulative, not just tier N's own dirs: the undefined-* rules are closed
 * world, and they are only sound when every symbol the code can legitimately
 * reach is present. Handing analyze one tier in isolation would report its
 * lower-tier dependencies as undefined and drown the real findings.
 *
 * Usage: php tools/audit/tier_dirs.php <N> [--tiers docs/audit/tiers.json]
 *                                          [--app <probe-app-dir>]
 *                                          [--only]   only tier N's own dirs
 */

$tier = $argv[1] ?? '';
$tiersPath = 'docs/audit/tiers.json';
$app = '/Users/taras/var/projects/symfony-demo-probe/app';
$only = false;
for ($i = 2; $i < count($argv); $i++) {
    if ($argv[$i] === '--tiers' && isset($argv[$i + 1])) { $tiersPath = $argv[++$i]; }
    elseif ($argv[$i] === '--app' && isset($argv[$i + 1])) { $app = $argv[++$i]; }
    elseif ($argv[$i] === '--only') { $only = true; }
}
if ($tier === '') { fwrite(STDERR, "tier_dirs: usage: tier_dirs.php <N>\n"); exit(2); }
$tier = str_starts_with($tier, 'T') ? $tier : 'T' . $tier;

$j = json_decode((string)@file_get_contents($tiersPath), true);
if (!is_array($j) || !isset($j['tiers'])) {
    fwrite(STDERR, "tier_dirs: no ladder at $tiersPath — run gen_tiers.php first\n");
    exit(2);
}

$out = [];
foreach ($j['tiers'] as $name => $info) {
    if ($only && $name !== $tier) { continue; }
    foreach ($info['dirs'] as $d) { $out[] = $app . '/' . $d; }
    if ($name === $tier) { break; }
}
// composer's own autoloader is part of every closed world: it declares the
// ClassLoader class the app's bootstrap names.
if (!$only) { array_unshift($out, $app . '/vendor/composer'); }

echo implode("\n", $out), "\n";
