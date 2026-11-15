<?php

// Latency / memory / parity bench for Semantic\Workspace.
//
//   php -d xdebug.mode=off tools/bench_semantic.php <dir> [--edits N] [--broken N] [--parity] [--cli-json FILE]
//
// Also self-compilable (see docs/design/semantic-core.md): the native binary loads the real
// prelude + stdlib sig through LibrarySymbols::load(); under Zend the same call works once
// Manticore\Main's functions are required and MANTICORE_PRELUDE / MANTICORE_STDLIB_SIG
// point at the repo (defaults below).
//
// --parity: for every error-free file, Workspace::diagnostics must equal that file's entries
// in `bin/manticore analyze --json <dir>` (or in --cli-json FILE). Exit 1 on a difference.
//
// --broken N: N random files (fixed seed), each mutated three ways (truncated at a random byte, a
// garbage line injected, a random line deleted); every mutation is updated into the Workspace and
// timed (update + diagnostics), the undefined.* diagnostics that appear in untouched dependents are
// counted (sticky declarations keep it at 0 unless the mutation deleted the declaration), and the
// file is restored and its diagnostics must return to the original set. Exit 1 otherwise.

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

/**
 * @return string[] code|line|col|message of the workspace diagnostics of $uri
 */
function bench_sig(\Semantic\Workspace $ws, string $uri): array
{
    $o = [];
    foreach ($ws->diagnostics($uri) as $d) { $o[] = $d->code . '|' . $d->line . '|' . $d->col . '|' . $d->message; }
    return $o;
}

function bench_is_undefined(string $sig): bool
{
    foreach (['undefined.class|', 'undefined.function|', 'undefined.method|', 'undefined.constant|'] as $c) {
        if (str_starts_with($sig, $c)) { return true; }
    }
    return false;
}

/** @return string[] names declared by class-likes, functions and `const`/`define` in the text */
function bench_decl_names(string $text): array
{
    $out = [];
    if (preg_match_all('/\b(?:class|interface|trait|enum|function|const)\s+&?([A-Za-z_][A-Za-z0-9_]*)|define\(\s*[\'"]([A-Za-z_][A-Za-z0-9_]*)/', $text, $m, PREG_SET_ORDER) > 0) {
        foreach ($m as $x) { $out[] = $x[2] ?? $x[1]; }
    }
    return $out;
}
$dir = '';
$edits = 200;
$parity = false;
$cliJson = '';
$broken = 0;
$n = count($argv);
for ($i = 1; $i < $n; $i++) {
    $a = $argv[$i];
    if ($a === '--edits') { $i++; $edits = (int)$argv[$i]; }
    elseif ($a === '--broken') { $i++; $broken = (int)$argv[$i]; }
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

if ($broken > 0 && count($files) > 0) {
    $seed = 777;
    $rnd = function (int $mod) use (&$seed): int {
        $seed = ($seed * 1103515245 + 12345) % 2147483648;
        return intdiv($seed, 65536) % max(1, $mod);
    };
    $base = [];
    $sigOf = function (string $f) use (&$base, $ws): array {
        return $base[$f] ??= bench_sig($ws, $f);
    };
    $picked = [];
    for ($k = 0; $k < $broken && $k < count($files); $k++) {
        do { $f = $files[$rnd(count($files))]; } while (isset($picked[$f]));
        $picked[$f] = true;
    }
    $tot = [];
    $mutations = 0;
    $flood = [];
    $legit = 0;
    $restoreDiffs = [];
    foreach (array_keys($picked) as $f) {
        $orig = $texts[$f];
        $names = bench_decl_names($orig);
        $deps = [];
        foreach ($files as $g) {
            if ($g === $f || count($deps) >= 12) { continue; }
            foreach ($names as $nm) {
                if (strlen($nm) > 3 && strpos($texts[$g], $nm) !== false) { $deps[] = $g; break; }
            }
        }
        $watch = array_merge([$f], $deps);
        foreach ($watch as $g) { $sigOf($g); }
        foreach (['truncate', 'garbage', 'delete-line'] as $kind) {
            $lines = explode("\n", $orig);
            if ($kind === 'truncate') { $mut = substr($orig, 0, $rnd(strlen($orig))); }
            elseif ($kind === 'garbage') { array_splice($lines, $rnd(count($lines)), 0, ['}} ) ] @@ class {{ $ -> ;;']); $mut = implode("\n", $lines); }
            else { array_splice($lines, $rnd(count($lines)), 1); $mut = implode("\n", $lines); }
            $mutations++;
            $t0 = hrtime(true);
            $ws->update($f, $mut);
            $ws->diagnostics($f);
            $tot[] = (hrtime(true) - $t0) / 1e6;
            foreach ($deps as $g) {
                $before = array_flip($base[$g]);
                foreach (bench_sig($ws, $g) as $sg) {
                    if (!bench_is_undefined($sg) || isset($before[$sg])) { continue; }
                    $gone = false;
                    foreach ($names as $nm) {
                        if (stripos($sg, $nm) !== false && !preg_match('/\b' . preg_quote($nm, '/') . '\b/i', $mut)) { $gone = true; break; }
                    }
                    if ($gone) { $legit++; } else { $flood[] = $kind . ' ' . $f . ' -> ' . $g . ': ' . $sg; }
                }
            }
            $ws->update($f, $orig);
            foreach ($watch as $g) {
                $now = bench_sig($ws, $g);
                $a = array_diff($now, $base[$g]);
                $b = array_diff($base[$g], $now);
                foreach ($a as $x) { $restoreDiffs[] = 'after restore, new in ' . $g . ': ' . $x; }
                foreach ($b as $x) { $restoreDiffs[] = 'after restore, lost in ' . $g . ': ' . $x; }
            }
        }
    }
    echo 'broken: files=' . count($picked) . ' mutations=' . $mutations . "\n";
    echo "per mutation (ms)     p50      p95      max\n";
    echo str_pad('update+diagnostics', 22) . str_pad(bench_ms(bench_pct($tot, 50)), 9) . str_pad(bench_ms(bench_pct($tot, 95)), 9) . bench_ms(max($tot)) . "\n";
    echo 'new undefined.* in untouched dependents (declaration kept): ' . count($flood) . '; (declaration deleted by the mutation, expected): ' . $legit . "\n";
    foreach (array_slice($flood, 0, 10) as $x) { echo substr($x, 0, 300) . "\n"; }
    echo 'restore: differences=' . count($restoreDiffs) . "\n";
    foreach (array_slice($restoreDiffs, 0, 10) as $x) { echo substr($x, 0, 300) . "\n"; }
    echo 'peak RSS after broken (getrusage ru_maxrss): ' . bench_rss_mb() . " MB\n";
    if (count($restoreDiffs) > 0) { $status = 1; }
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
