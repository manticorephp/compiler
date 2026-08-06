<?php
/**
 * triage.php — turn a tier's raw analyze output into findings.
 *
 * Raw counts are useless on their own. A tier's closed world deliberately stops
 * at tier N, so every reference UP the ladder reads as undefined — at T2 that
 * artifact outnumbers the real findings ten to one. Four buckets:
 *
 *   parse.error      the parser cannot read this syntax. Always a finding, and
 *                    the highest-confidence kind: no interpretation needed.
 *   out-of-scope     the symbol is declared in a HIGHER tier. A tiering
 *                    artifact, not a gap. Dropped.
 *   blind-spot       the symbol is declared INSIDE this tier's own source set
 *                    and the analyzer failed to see it. Analyzer noise; must be
 *                    zero after calibration (tools/audit/calibrate.sh).
 *   absent           declared nowhere in the whole application. A real gap:
 *                    either PHP provides it and manticore does not, or the app
 *                    references a package it never installed.
 *
 * Advisory rules (array.no-value-type) are counted and dropped: correct
 * observations about third-party docblocks, not gaps.
 *
 * Usage: php tools/audit/triage.php <tier> [--app <dir>] [--json]
 */

$tier = $argv[1] ?? '';
$app = '/Users/taras/var/projects/symfony-demo-probe/app';
$asJson = false;
for ($i = 2; $i < count($argv); $i++) {
    if ($argv[$i] === '--app' && isset($argv[$i + 1])) { $app = $argv[++$i]; }
    elseif ($argv[$i] === '--json') { $asJson = true; }
}
if ($tier === '') { fwrite(STDERR, "triage: usage: triage.php <tier>\n"); exit(2); }
$tierKey = str_starts_with($tier, 'T') ? $tier : 'T' . $tier;
$num = (int)substr($tierKey, 1);

$diagPath = "docs/audit/data/analyze-t$num.json";
// Absent and UNPARSEABLE are different failures, and saying "no analyze output"
// for a 741 KB file that simply does not decode is the audit lying about its own
// instrument. `manticore analyze --json` currently emits malformed UTF-8 for
// this corpus (our json_encode neither validates nor rejects it, where php's
// json_encode returns false), so the whole static lane degrades SILENTLY.
$raw = @file_get_contents($diagPath);
if ($raw === false) {
    fwrite(STDERR, "triage: no analyze output at $diagPath — run run_tier.sh $num\n");
    exit(2);
}
$d = json_decode((string)$raw, true);
if (!is_array($d)) {
    fwrite(STDERR, "triage: analyze output at $diagPath is UNPARSEABLE ("
        . strlen((string)$raw) . " bytes): " . json_last_error_msg() . "\n");
    exit(2);
}

$ladder = json_decode((string)file_get_contents('docs/audit/tiers.json'), true);

/** Declared symbols per directory set. */
function scan(array $dirs): array
{
    $fn = [];
    $cls = [];
    foreach ($dirs as $dir) {
        if (!is_dir($dir)) { continue; }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
        foreach ($it as $f) {
            if (!$f->isFile() || $f->getExtension() !== 'php') { continue; }
            $src = (string)file_get_contents($f->getPathname());
            $ns = '';
            if (preg_match('/^\s*namespace\s+([^;{\s]+)/m', $src, $m)) { $ns = $m[1] . '\\'; }
            if (preg_match_all('/^\s*(?:final\s+|abstract\s+|readonly\s+)*function\s+&?([A-Za-z_][A-Za-z0-9_]*)\s*\(/m', $src, $m)) {
                foreach ($m[1] as $n) { $fn[strtolower($n)] = true; }
            }
            if (preg_match_all('/^\s*(?:final\s+|abstract\s+|readonly\s+)*(?:class|interface|trait|enum)\s+([A-Za-z_][A-Za-z0-9_]*)/m', $src, $m)) {
                foreach ($m[1] as $n) { $cls[strtolower($ns . $n)] = true; }
            }
        }
    }
    return [$fn, $cls];
}

$inScope = [];
$above = [];
$seen = false;
foreach ($ladder['tiers'] as $name => $info) {
    foreach ($info['dirs'] as $dd) {
        if ($seen) { $above[] = $app . '/' . $dd; } else { $inScope[] = $app . '/' . $dd; }
    }
    if ($name === $tierKey) { $seen = true; }
}
$inScope[] = $app . '/vendor/composer';

[$fnIn, $clsIn] = scan($inScope);
[$fnUp, $clsUp] = scan($above);

$buckets = ['parse' => [], 'blind' => [], 'above' => [], 'absent' => []];
$advisory = 0;
$other = [];

foreach ($d as $x) {
    $code = (string)($x['code'] ?? '?');
    $file = str_replace($app . '/', '', (string)($x['file'] ?? '?'));
    $at = $file . ':' . ($x['line'] ?? 0);
    if ($code === 'parse.error') {
        $buckets['parse'][] = ['at' => $at, 'msg' => (string)($x['message'] ?? '')];
        continue;
    }
    if ($code === 'array.no-value-type') { $advisory++; continue; }
    if (!str_starts_with($code, 'undefined.')) {
        $other[$code] = ($other[$code] ?? 0) + 1;
        continue;
    }
    if (!preg_match('/^unknown \S+ (.+?)(?:\(\))?$/', (string)($x['message'] ?? ''), $m)) { continue; }
    $sym = $m[1];
    if ($code === 'undefined.method' || $code === 'undefined.class-const') {
        // Resolution through an interface-typed receiver is not decidable
        // statically. Counted, never promoted to a finding.
        $other[$code] = ($other[$code] ?? 0) + 1;
        continue;
    }
    if ($code === 'undefined.function') {
        $bare = $sym;
        $bs = strrpos($bare, '\\');
        if ($bs !== false) { $bare = substr($bare, $bs + 1); }
        $bare = strtolower($bare);
        $where = isset($fnIn[$bare]) ? 'blind' : (isset($fnUp[$bare]) ? 'above' : 'absent');
    } else {
        $k = strtolower(ltrim($sym, '\\'));
        $where = isset($clsIn[$k]) ? 'blind' : (isset($clsUp[$k]) ? 'above' : 'absent');
    }
    if (!isset($buckets[$where][$code])) { $buckets[$where][$code] = []; }
    if (!isset($buckets[$where][$code][$sym])) { $buckets[$where][$code][$sym] = $at; }
}

if ($asJson) {
    echo json_encode(['tier' => $tierKey, 'buckets' => $buckets, 'advisory' => $advisory,
                      'other' => $other], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}

echo "== $tierKey triage ==\n";

// Parse errors first: highest confidence, and they collapse hard. Group by the
// syntax construct rather than by site.
if ($buckets['parse']) {
    $byMsg = [];
    foreach ($buckets['parse'] as $p) { $byMsg[$p['msg']][] = $p['at']; }
    echo "\nFINDING parse.error — ", count($buckets['parse']), " site(s), ",
         count($byMsg), " distinct diagnostic(s)\n";
    foreach ($byMsg as $msg => $sites) {
        printf("  %-46s x%-2d  %s\n", $msg, count($sites), $sites[0]);
    }
}

$nAbsent = 0;
foreach ($buckets['absent'] as $code => $syms) { $nAbsent += count($syms); }
if ($nAbsent) {
    echo "\nFINDING absent — declared nowhere in the application\n";
    foreach ($buckets['absent'] as $code => $syms) {
        echo "  $code x", count($syms), "\n";
        $n = 0;
        foreach ($syms as $sym => $at) {
            if (++$n > 14) { echo "    … and ", count($syms) - 14, " more\n"; break; }
            printf("    %-56s %s\n", $sym, $at);
        }
    }
}

$nBlind = 0;
foreach ($buckets['blind'] as $code => $syms) { $nBlind += count($syms); }
$nAbove = 0;
foreach ($buckets['above'] as $code => $syms) { $nAbove += count($syms); }

echo "\n";
printf("  %-14s %d  (analyzer noise — must be 0 after calibration)\n", 'blind-spot', $nBlind);
printf("  %-14s %d  (declared in a higher tier — tiering artifact)\n", 'out-of-scope', $nAbove);
printf("  %-14s %d  (third-party docblocks)\n", 'advisory', $advisory);
foreach ($other as $c => $n) { printf("  %-14s %d\n", $c, $n); }

if ($nBlind > 0) {
    echo "\n  blind spots (first 10):\n";
    $n = 0;
    foreach ($buckets['blind'] as $code => $syms) {
        foreach ($syms as $sym => $at) {
            if (++$n > 10) { break 2; }
            printf("    %-56s %s\n", $sym, $at);
        }
    }
}
