<?php
/**
 * cap_run.php — run the capability probe suite and emit the status matrix.
 *
 * A capability probe is one tiny PHP file exercising ONE Zend behaviour that
 * symfony depends on and that no static rule can see. Each probe is run twice:
 * under `php` (the oracle) and as a compiled manticore binary. The outcome is
 * one of:
 *
 *   PASS     identical stdout+stderr+exit status
 *   DIFF     both ran, outputs differ            (S0 if the native run looks
 *                                                 plausible, S1 if it crashed)
 *   COMPILE  manticore could not compile it      (S2)
 *   CRASH    native binary died on a signal      (S1)
 *   ORACLE   `php` itself failed — probe is bad, fix the probe
 *
 * Probes live in two places by their CURRENT status, and that placement is
 * load-bearing:
 *
 *   tests/aot/cases/cap_*.php   probes that PASS today. Auto-discovered by
 *                               tests/aot/run.sh, so they gate forever.
 *   tests/audit/probes/cap_*.php probes that FAIL today. A permanently-red case
 *                               in tests/aot/cases would break the gate and get
 *                               muted — which is exactly how a KNOWN gap turns
 *                               into an UNKNOWN one.
 *
 * When an epic closes a gap it moves that probe from tests/audit/probes/ into
 * tests/aot/cases/ in the same commit. The audit is meant to self-liquidate.
 *
 * Probe header metadata (parsed, both directories):
 *   // @epic: <slug>      which follow-on epic owns this gap
 *   // @why:  <text>      what in symfony needs it
 *
 * Usage:
 *   php tools/audit/cap_run.php [--filter <substr>] [--regen] [--verify]
 *
 *   --regen   (re)generate tests/audit/probes/expected/*.out from `php`
 *   --verify  regenerate into a temp buffer and fail if it differs from what
 *             is checked in — proves no expected file was hand-written
 */

$root = dirname(dirname(__DIR__));
chdir($root);

$filter = '';
$regen = false;
$verify = false;
for ($i = 1; $i < count($argv); $i++) {
    if ($argv[$i] === '--filter' && isset($argv[$i + 1])) { $filter = $argv[++$i]; }
    elseif ($argv[$i] === '--regen') { $regen = true; }
    elseif ($argv[$i] === '--verify') { $verify = true; }
}

$probeDir = 'tests/audit/probes';
$caseDir = 'tests/aot/cases';
$expDir = $probeDir . '/expected';
$tmp = sys_get_temp_dir() . '/mc_cap_' . getmypid();
@mkdir($tmp, 0777, true);
@mkdir($expDir, 0777, true);
@mkdir('tests/audit/data', 0777, true);

$mc = $root . '/bin/manticore';
if (!is_file($mc)) { fwrite(STDERR, "cap_run: no bin/manticore — build the worktree first\n"); exit(2); }
putenv('MANTICORE_PRELUDE=' . $root . '/prelude');

/** @return array{0:string,1:int} combined output, exit status */
function run(string $cmd): array
{
    // A pipe eats the exit code, so redirect to a file and read it back.
    $out = sys_get_temp_dir() . '/mc_cap_out_' . getmypid();
    $rc = 0;
    $ignored = [];
    exec($cmd . ' > ' . escapeshellarg($out) . ' 2>&1', $ignored, $rc);
    $text = (string)@file_get_contents($out);
    @unlink($out);
    return [$text, $rc];
}

/** Probe header metadata. */
function meta(string $src, string $key, string $default): string
{
    if (preg_match('~^//\s*@' . preg_quote($key, '~') . ':\s*(.+)$~m', $src, $m)) {
        return trim($m[1]);
    }
    return $default;
}

$probes = [];
foreach ([[$probeDir, 'audit'], [$caseDir, 'gate']] as [$dir, $where]) {
    foreach (glob($dir . '/cap_*.php') ?: [] as $p) {
        $name = basename($p, '.php');
        if ($filter !== '' && !str_contains($name, $filter)) { continue; }
        $probes[$name] = ['path' => $p, 'where' => $where];
    }
}
ksort($probes);

if (count($probes) === 0) { fwrite(STDERR, "cap_run: no probes matched\n"); exit(2); }

$rows = [];
$counts = ['PASS' => 0, 'DIFF' => 0, 'COMPILE' => 0, 'CRASH' => 0, 'ORACLE' => 0];

foreach ($probes as $name => $info) {
    $src = (string)file_get_contents($info['path']);
    $epic = meta($src, 'epic', 'unassigned');

    [$oracleOut, $oracleRc] = run('php ' . escapeshellarg($info['path']));

    // The gate directory keeps its expected output next to the case, the audit
    // directory keeps it under expected/. Either way it is GENERATED.
    $expPath = $info['where'] === 'gate'
        ? 'tests/aot/expected/' . $name . '.out'
        : $expDir . '/' . $name . '.out';

    if ($regen && $info['where'] === 'audit') { file_put_contents($expPath, $oracleOut); }
    if ($verify && is_file($expPath) && rtrim((string)file_get_contents($expPath)) !== rtrim($oracleOut)) {
        fwrite(STDERR, "cap_run: VERIFY FAIL — $expPath does not match a fresh `php` run\n");
        exit(1);
    }

    $bin = $tmp . '/' . $name;
    [$cOut, $cRc] = run(escapeshellarg($mc) . ' compile ' . escapeshellarg($info['path'])
        . ' -o ' . escapeshellarg($bin) . ' --no-analyze');

    if ($oracleRc !== 0 && $oracleOut === '') {
        $status = 'ORACLE';
        $nativeOut = '';
    } elseif ($cRc !== 0) {
        $status = 'COMPILE';
        // Keep the first compiler error — it is the evidence for the finding.
        $nativeOut = trim(explode("\n", trim($cOut))[0] ?? '');
    } else {
        [$nativeOut, $nRc] = run(escapeshellarg($bin));
        if ($nRc > 128) { $status = 'CRASH'; }
        elseif (rtrim($nativeOut) === rtrim($oracleOut) && $nRc === $oracleRc) { $status = 'PASS'; }
        else { $status = 'DIFF'; }
    }

    $counts[$status]++;
    $rows[] = [
        $name, $info['where'], $status, $epic,
        substr(sha1($oracleOut), 0, 8), substr(sha1($nativeOut), 0, 8),
    ];

    printf("%-8s %-32s %-10s %s\n", $status, $name, $info['where'], $epic);
    if ($status === 'DIFF' || $status === 'COMPILE') {
        foreach (diff_lines($oracleOut, $nativeOut) as $d) { echo "           $d\n"; }
    }
}

/** First few differing lines, php on the left. */
function diff_lines(string $a, string $b): array
{
    $al = explode("\n", rtrim($a));
    $bl = explode("\n", rtrim($b));
    $out = [];
    $n = max(count($al), count($bl));
    for ($i = 0; $i < $n && count($out) < 6; $i++) {
        $x = $al[$i] ?? '<none>';
        $y = $bl[$i] ?? '<none>';
        if ($x !== $y) { $out[] = sprintf('php: %-30s  mc: %s', $x, $y); }
    }
    return $out;
}

$fh = fopen('tests/audit/data/capability.tsv', 'w');
fwrite($fh, "probe\tlocation\tstatus\towning_epic\tphp_out_sha\tmc_out_sha\n");
foreach ($rows as $r) { fwrite($fh, implode("\t", $r) . "\n"); }
fclose($fh);

echo "\n";
foreach ($counts as $k => $v) { if ($v > 0) { echo "$k=$v "; } }
echo "\n-> tests/audit/data/capability.tsv\n";

// This is an audit tool: a DIFF is a finding, not a failure. Only a broken
// probe (ORACLE) or a failed --verify is an error worth a non-zero exit.
exit($counts['ORACLE'] > 0 ? 1 : 0);
