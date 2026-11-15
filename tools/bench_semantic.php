<?php

// Latency / memory / parity bench for Semantic\Workspace.
//
//   php -d xdebug.mode=off tools/bench_semantic.php <dir> [--edits N] [--parity] [--cli-json FILE]
//
// Also self-compilable (see docs/design/semantic-core.md): the native binary loads the real
// prelude + stdlib sig through LibrarySymbols::load(); under Zend the same call works once
// Manticore\Main's functions are required and MANTICORE_PRELUDE / MANTICORE_STDLIB_SIG
// point at the repo (defaults below).
//
// --parity: for every error-free file, Workspace::diagnostics must equal that file's entries
// in `bin/manticore analyze --json <dir>` (or in --cli-json FILE). Exit 1 on a difference.

if (!class_exists('Semantic\Workspace')) {
    $root = dirname(__DIR__);
    spl_autoload_register(function (string $cls) use ($root): void {
        $f = $root . '/src/' . str_replace('\\', '/', $cls) . '.php';
        if (is_file($f)) { require $f; }
    });
    foreach (['MANTICORE_PRELUDE' => '/prelude', 'MANTICORE_STDLIB_SIG' => '/lib/manticore_stdlib.o.sig'] as $k => $rel) {
        if (getenv($k) === false) { putenv($k . '=' . $root . $rel); }
    }
    require_once $root . '/src/Manticore/Main.php';
}

/** @return string[] */
function bench_collect(string $dir): array
{
    $out = [];
    $names = scandir($dir);
    sort($names);
    foreach ($names as $n) {
        if ($n === '.' || $n === '..') { continue; }
        $p = $dir . '/' . $n;
        if (is_dir($p)) {
            foreach (bench_collect($p) as $q) { $out[] = $q; }
        } elseif (str_ends_with($n, '.php')) {
            $out[] = $p;
        }
    }
    return $out;
}

/** @param float[] $v */
function bench_pct(array $v, int $p): float
{
    if (count($v) === 0) { return 0.0; }
    sort($v);
    $i = intdiv(count($v) * $p, 100);
    if ($i >= count($v)) { $i = count($v) - 1; }
    return $v[$i];
}

function bench_ms(float $v): string
{
    return number_format($v, 3, '.', '');
}

function bench_rss_mb(): string
{
    $ru = getrusage();
    $m = (int)($ru['ru_maxrss'] ?? 0);
    // ru_maxrss is bytes on macOS, KiB on Linux.
    $mb = PHP_OS === 'Darwin' ? $m / 1048576 : $m / 1024;
    return number_format($mb, 1, '.', '');
}

/**
 * One entry per diagnostic: the CLI's JSON object text, grouped by its `"file":` token.
 *
 * @return array<string, string[]>
 */
function bench_group_json(string $json): array
{
    $out = [];
    $json = trim($json);
    if (strlen($json) < 3) { return $out; }
    $parts = explode('},{"file":', substr($json, 1, -1));
    $first = true;
    foreach ($parts as $part) {
        $obj = $first ? $part : '{"file":' . $part;
        $first = false;
        if (!str_ends_with($obj, '}')) { $obj = $obj . '}'; }
        $end = strpos($obj, ',"line":');
        $file = substr($obj, 8, $end - 8);
        if (!isset($out[$file])) { $out[$file] = []; }
        $out[$file][] = $obj;
    }
    return $out;
}

$dir = '';
$edits = 200;
$parity = false;
$cliJson = '';
$n = count($argv);
for ($i = 1; $i < $n; $i++) {
    $a = $argv[$i];
    if ($a === '--edits') { $i++; $edits = (int)$argv[$i]; }
    elseif ($a === '--parity') { $parity = true; }
    elseif ($a === '--cli-json') { $i++; $cliJson = $argv[$i]; }
    else { $dir = rtrim($a, '/'); }
}
if ($dir === '') { echo "usage: bench_semantic.php <dir> [--edits N] [--parity] [--cli-json FILE]\n"; exit(64); }

$files = bench_collect($dir);
$texts = [];
foreach ($files as $f) { $texts[$f] = (string)file_get_contents($f); }
echo 'dir=' . $dir . ' files=' . count($files) . ' edits=' . $edits . ' runtime=' . (PHP_OS === 'Darwin' ? 'macOS' : PHP_OS) . "\n";

$t0 = hrtime(true);
$lib = \Semantic\LibrarySymbols::load();
if (count($lib->stdlibFunctionNames()) === 0) {
    // Zend host: Manticore\file_exists & co. are throwing stubs, so load() finds no sig.
    // Same data through plain Zend functions (the prelude half of load() works).
    $sig = getenv('MANTICORE_STDLIB_SIG');
    $names = [];
    if (is_string($sig) && is_file($sig)) {
        foreach (\Manticore\Sig::declsFromJson((string)file_get_contents($sig)) as $decl) { $names[] = $decl->name; }
    }
    $lib = \Semantic\LibrarySymbols::fromParts($lib->preludeFiles(), \Semantic\LibrarySymbols::normalizeNames($names));
}
$ws = new \Semantic\Workspace($lib, true);
$tLib = (hrtime(true) - $t0) / 1e6;
echo 'library load (prelude parse + sig): ' . bench_ms($tLib) . " ms, prelude files=" . count($lib->preludeFiles()) . ', stdlib fns=' . count($lib->stdlibFunctionNames()) . "\n";

$t0 = hrtime(true);
foreach ($files as $f) { $ws->open($f, $texts[$f]); }
$tOpen = (hrtime(true) - $t0) / 1e6;
echo 'cold open of ' . count($files) . ' files: ' . bench_ms($tOpen) . " ms\n";

$t0 = hrtime(true);
$nd = 0;
$diagFiles = [];
foreach ($files as $f) {
    $c = count($ws->diagnostics($f));
    $nd += $c;
}
$tAll = (hrtime(true) - $t0) / 1e6;
echo 'diagnostics for all files: ' . bench_ms($tAll) . ' ms, ' . $nd . " diagnostics\n";
echo 'peak RSS after cold load (getrusage ru_maxrss): ' . bench_rss_mb() . " MB\n";

$status = 0;
if ($parity) {
    if ($cliJson !== '') {
        $raw = (string)file_get_contents($cliJson);
    } else {
        $bin = dirname(__DIR__) . '/bin/manticore';
        $raw = (string)shell_exec($bin . ' analyze --json ' . escapeshellarg($dir) . ' 2>/dev/null');
    }
    if (trim($raw) === '' || !is_array(json_decode($raw, true))) {
        fwrite(STDERR, "parity: the CLI output is empty or not valid JSON (is bin/manticore built and does it accept --json?)\n");
        exit(1);
    }
    $cli = bench_group_json($raw);
    $diffs = [];
    $checked = 0;
    $skipped = 0;
    foreach ($files as $f) {
        $key = '"' . $f . '"';
        $theirs = $cli[$key] ?? [];
        $hasParseError = false;
        foreach ($theirs as $o) {
            if (str_contains($o, '"code":"parse.error"')) { $hasParseError = true; }
        }
        if ($hasParseError) { $skipped++; continue; }
        $checked++;
        $mine = [];
        $json = \Analyze\Report::json($ws->diagnostics($f));
        foreach (bench_group_json($json) as $objs) {
            foreach ($objs as $o) { $mine[] = $o; }
        }
        $before = count($diffs);
        foreach ($mine as $o) {
            if (!in_array($o, $theirs, true)) { $diffs[] = 'workspace-only: ' . $o; }
        }
        foreach ($theirs as $o) {
            if (!in_array($o, $mine, true)) { $diffs[] = 'cli-only:       ' . $o; }
        }
        if (count($diffs) === $before && count($mine) !== count($theirs)) {
            $diffs[] = 'duplicate count differs for ' . $f . ': workspace=' . count($mine) . ' cli=' . count($theirs);
        }
    }
    $known = [];
    foreach ($files as $f) { $known['"' . $f . '"'] = true; }
    foreach ($cli as $key => $objs) {
        if (!isset($known[$key])) { $diffs[] = 'cli-only file unknown to the workspace: ' . $key; }
    }
    echo 'parity: checked=' . $checked . ' skipped(parse errors)=' . $skipped . ' cli diagnostics=' . substr_count($raw, '"code":') . ' differences=' . count($diffs) . "\n";
    $shown = 0;
    foreach ($diffs as $d) {
        if ($shown >= 10) { break; }
        echo substr($d, 0, 300) . "\n";
        $shown++;
    }
    if (count($diffs) > 0) { $status = 1; }
}

if ($edits > 0 && count($files) > 0) {
    $seed = 12345;
    $upd = [];
    $dg = [];
    $tot = [];
    $parse = [];
    for ($e = 0; $e < $edits; $e++) {
        $seed = ($seed * 1103515245 + 12345) % 2147483648;
        $f = $files[intdiv($seed, 65536) % count($files)];
        $texts[$f] = $texts[$f] . "\n// bench edit " . $e . "\n";
        $p0 = hrtime(true);
        \Parser\Parser::parseTolerant($texts[$f], $f);
        $p1 = hrtime(true);
        $ws->update($f, $texts[$f]);
        $u1 = hrtime(true);
        $ws->diagnostics($f);
        $d1 = hrtime(true);
        $parse[] = ($p1 - $p0) / 1e6;
        $upd[] = ($u1 - $p1) / 1e6;
        $dg[] = ($d1 - $u1) / 1e6;
        $tot[] = ($d1 - $p1) / 1e6;
    }
    echo "per edit (ms)         p50      p95      max\n";
    foreach (['update+diagnostics' => $tot, 'update (parse+index+names)' => $upd, 'diagnostics (rules)' => $dg, 'parse alone (reference)' => $parse] as $label => $v) {
        echo str_pad($label, 26) . str_pad(bench_ms(bench_pct($v, 50)), 9) . str_pad(bench_ms(bench_pct($v, 95)), 9) . bench_ms(max($v)) . "\n";
    }
    echo 'peak RSS after edits (getrusage ru_maxrss): ' . bench_rss_mb() . " MB; memory_get_peak_usage: " . number_format(memory_get_peak_usage() / 1048576, 1, '.', '') . " MB\n";
}
exit($status);
