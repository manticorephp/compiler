<?php
/**
 * Static MIR node-kind frequency, from `dump-mir` output.
 *
 *   php tools/prof/kindfreq.php <file.php> [more.php ...]
 *
 * `Walk::children` (and every other kind-dispatch chain) costs
 * `position-in-chain` string comparisons per node, so the total is
 * `sum(freq(kind) * position(kind))` — which is minimised by ordering the arms
 * by descending frequency. This counts the frequencies instead of guessing them.
 *
 * ⚠ STATIC counts, not visit counts. Visits are roughly proportional (every
 * generic traversal walks the whole body), but a kind that only appears inside
 * rarely-walked bodies is over-weighted here.
 */

$bin = getenv('MC') !== false ? (string)getenv('MC') : './bin/manticore';
$files = array_slice($argv, 1);
if ($files === []) {
    fwrite(STDERR, "usage: kindfreq.php <file.php> [...]\n");
    exit(2);
}

$kinds = [];
foreach (explode("\n", (string)file_get_contents('src/Compile/Mir/Node.php')) as $ln) {
    if (preg_match("/const KIND_[A-Z0-9_]+\s*=\s*'([a-z0-9_]+)'/", $ln, $m)) { $kinds[$m[1]] = 0; }
}

foreach ($files as $f) {
    $out = (string)shell_exec(escapeshellcmd($bin) . ' dump-mir ' . escapeshellarg($f) . ' 2>/dev/null');
    foreach (explode("\n", $out) as $ln) {
        $t = ltrim($ln);
        if ($t === '' || $t[0] === ';') { continue; }
        // "%12 = load_local n : unknown" or a bare statement "ternary %6 then {"
        if (preg_match('/^%\d+ = ([a-z0-9_]+)/', $t, $m) || preg_match('/^([a-z0-9_]+)/', $t, $m)) {
            if (isset($kinds[$m[1]])) { $kinds[$m[1]]++; }
        }
    }
}

arsort($kinds);
$total = array_sum($kinds);
printf("%-22s %10s %8s\n", 'kind', 'count', '%');
foreach ($kinds as $k => $n) {
    if ($n === 0) { continue; }
    printf("%-22s %10s %7.1f%%\n", $k, number_format($n), $total > 0 ? 100.0 * $n / $total : 0.0);
}
