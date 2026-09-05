<?php
/**
 * The ownership classifier for codegen builtins.
 *
 * `docs/design/builtin-ownership.md` states the model: a user call carries a
 * complete ownership contract (the caller releases a fresh rc arg temp, the
 * callee retains what it keeps, a return is +1) and a CODEGEN BUILTIN carries
 * none of it. Whether the caller MAY free a builtin's argument is a per-name
 * question with three answers — A (the result cannot reference the argument),
 * B (the result is a fresh array that copied element WORDS, so it must
 * CO-OWN), C (the result IS an element, so the builtin must hand back +1 and
 * `EmitLlvm::isFreshCellTemp` must name it).
 *
 * Every leak the epic closed was found by hand: LEAK ratio -> split the loops
 * -> cut the callee -> read the `.ll`. That does not scale to the ~40 names
 * left. This runs the same experiment mechanically, one probe per name:
 *
 *     for (...) { $acc = $acc + <consumer>(<builtin>(<fresh temp>)); }
 *
 * where the fresh temp is `explode(",", $s . $r)` (an array), `$s . $r` (a
 * string) or `json_encode($s . $r)` (a cell). The probe scales its own work by
 * `$argc`, exactly like a `bench/cases/*.php`, so the harness gets two points
 * by passing a dummy argument and reports the RSS ratio.
 *
 * TWO COLUMNS, and they answer different halves of the same change:
 *
 *   * `leak`   — RSS at 1x vs Nx. A ratio near the scale is a MISSING release.
 *   * `parity` — the same probe under `php`. A DIFF is an OVER-release: the
 *                argument was freed under a live reader, which is exactly what
 *                a class-B retain or a class-C `+1` is there to prevent.
 *
 * The retain and the release are ONE change, so a name is only converted when
 * both columns are clean. A name that reads `ok / MATCH` today may be
 * ACCIDENTALLY correct — `implode` and `in_array` measure flat only because a
 * `boxToCell` rebuild frees the source as a side effect — so the table says
 * what is measured, never what is proven.
 *
 *   php tools/ownership_probe.php                 # the whole table
 *   php tools/ownership_probe.php -k array_       # only matching names
 *   MANT=../other/bin/manticore php tools/...     # A/B another compiler
 *   SCALE=4 ITERS=200000 KEEP=1 php tools/...     # wider arm / keep the probes
 *
 * Not part of any gate yet — Step 2 of the handoff is to make `LEAK on 0`
 * block a merge the way `failed 0` does.
 *
 * ⚠ A probe statement must be an INT no-op fed into something PRINTED. A
 * result nothing reads is dead code and LLVM deletes the loop with it; a
 * self-assign (`$out = $out;`) has its own rc shape and lies.
 */

$root = \dirname(__DIR__);
$mant = \getenv('MANT') !== false ? (string)\getenv('MANT') : $root . '/bin/manticore';
$php  = \getenv('PHP') !== false ? (string)\getenv('PHP') : 'php';
$scale = (int)(\getenv('SCALE') !== false ? \getenv('SCALE') : 2);
$iters = (int)(\getenv('ITERS') !== false ? \getenv('ITERS') : 120000);
$keep  = \getenv('KEEP') === '1';

$filter = '';
for ($i = 1; $i < $argc; $i++) {
    if ($argv[$i] === '-k' && isset($argv[$i + 1])) { $filter = $argv[$i + 1]; $i++; }
}

if (!\is_file($mant)) {
    \fwrite(\STDERR, "no $mant — run bin/build first\n");
    exit(1);
}

/**
 * One probe.
 *
 *  name     the builtin under test
 *  class    A / B / C from the model, or '?' when unclassified
 *  pre      statements emitted before the measured expression, so a by-ref
 *           builtin (`array_pop`) has an lvalue to take; `{T}` expands to the
 *           fresh temp for the shape
 *  expr     the measured expression; `{T}` = the fresh temp, `$t` = the `pre`
 *           local. Must evaluate to an INT
 *  shape    which fresh temp `{T}` is: arr | str | cell
 *
 * @var list<array{name:string,class:string,shape:string,pre:string,expr:string}>
 */
$T = [
    // ── controls: already converted, must stay flat ──────────────────────
    ['count',            'A', 'arr',  '', 'count({T})'],
    ['sizeof',           'A', 'arr',  '', 'sizeof({T})'],
    ['implode',          'A', 'arr',  '', 'strlen(implode("-", {T}))'],
    ['explode',          'B', 'str',  '', 'count(explode(",", {T}))'],
    ['array_keys',       'B', 'arr',  '', 'count(array_keys({T}))'],
    ['array_values',     'B', 'arr',  '', 'count(array_values({T}))'],
    ['array_reverse',    'B', 'arr',  '', 'count(array_reverse({T}))'],
    ['array_slice',      'B', 'arr',  '', 'count(array_slice({T}, 0, 2))'],
    ['max',              'C', 'arr',  '', 'strlen((string)max({T}))'],
    ['min',              'C', 'arr',  '', 'strlen((string)min({T}))'],
    ['json_encode',      'A', 'str',  '', 'strlen((string)json_encode({T}))'],
    ['json_decode',      'A', 'cell', '', 'strlen((string)json_decode({T}))'],

    // ── A: the result cannot reference the argument ──────────────────────
    ['in_array',         'A', 'arr',  '', '(int)in_array("beta", {T}, true)'],
    ['array_key_exists', 'A', 'arr',  '', '(int)array_key_exists(1, {T})'],
    ['array_sum',        'A', 'arr',  '', '(int)array_sum(array_map("strlen", {T}))'],
    ['array_product',    'A', 'arr',  '', '(int)array_product(array_map("strlen", {T}))'],
    ['array_is_list',    'A', 'arr',  '', '(int)array_is_list({T})'],
    ['array_search',     'C', 'arr',  '', '(int)array_search("beta", {T}, true)'],
    ['str_contains',     'A', 'str',  '', '(int)str_contains({T}, "beta")'],

    // ── B: a fresh array built by copying element WORDS ──────────────────
    ['array_merge',      'B', 'arr',  '', 'count(array_merge({T}, ["z"]))'],
    ['array_flip',       'B', 'arr',  '', 'count(array_flip({T}))'],
    ['array_unique',     'B', 'arr',  '', 'count(array_unique({T}))'],
    ['array_combine',    'B', 'arr',  '', 'count(array_combine({T}, {T}))'],
    ['array_fill_keys',  'B', 'arr',  '', 'count(array_fill_keys({T}, 1))'],
    ['array_diff',       'B', 'arr',  '', 'count(array_diff({T}, ["beta"]))'],
    ['array_intersect',  'B', 'arr',  '', 'count(array_intersect({T}, ["beta"]))'],
    ['array_map',        'B', 'arr',  '', 'count(array_map("strtoupper", {T}))'],
    ['array_filter',     'B', 'arr',  '', 'count(array_filter({T}, "strlen"))'],
    ['str_split',        'B', 'str',  '', 'count(str_split({T}, 3))'],
    ['preg_split',       'B', 'str',  '', 'count(preg_split("/,/", {T}))'],
    // php 8.5 DEPRECATES a defaulted $escape and prints the notice, which
    // reads as a parity DIFF that is not one — pass every optional arg.
    ['str_getcsv',       'B', 'str',  '', 'count(str_getcsv({T}, ",", chr(34), chr(92)))'],
    ['range',            'B', 'str',  '', 'count(range(1, strlen({T})))'],
    ['array_fill',       'B', 'str',  '', 'count(array_fill(0, strlen({T}), "x"))'],

    // ── C: the result IS an element or a key of the argument ─────────────
    ['current',          'C', 'arr',  '', 'strlen((string)current({T}))'],
    ['key',              'C', 'arr',  '', '(int)key({T})'],
    ['reset',            'C', 'arr',  '$t = {T};', 'strlen((string)reset($t))'],
    ['end',              'C', 'arr',  '$t = {T};', 'strlen((string)end($t))'],
    ['next',             'C', 'arr',  '$t = {T};', 'strlen((string)next($t))'],
    ['prev',             'C', 'arr',  '$t = {T};', 'strlen((string)prev($t))'],
    ['array_pop',        'C', 'arr',  '$t = {T};', 'strlen((string)array_pop($t))'],
    ['array_shift',      'C', 'arr',  '$t = {T};', 'strlen((string)array_shift($t))'],
    ['array_first',      'C', 'arr',  '', 'strlen((string)array_first({T}))'],
    ['array_last',       'C', 'arr',  '', 'strlen((string)array_last({T}))'],
    ['array_key_first',  'C', 'arr',  '', '(int)array_key_first({T})'],
    ['array_key_last',   'C', 'arr',  '', '(int)array_key_last({T})'],
];

/** The fresh, OWNED temp of each shape — a value with no other owner, so a
 *  missing release strands one allocation per iteration. */
function freshTemp(string $shape): string
{
    if ($shape === 'arr')  { return 'explode(",", $s . $r)'; }
    if ($shape === 'cell') { return '(string)json_encode($s . $r)'; }
    return '($s . $r)';
}

function probeSource(array $e): string
{
    $temp = freshTemp($e[2]);
    $pre  = \str_replace('{T}', $temp, $e[3]);
    $expr = \str_replace('{T}', $temp, $e[4]);
    $body = $pre === '' ? '' : '    ' . $pre . "\n";
    return "<?php\n"
        . "// GENERATED by tools/ownership_probe.php — do not edit.\n"
        . "// An explicit numeric arg is an absolute iteration count; any other\n"
        . "// arg (the dummy the harness passes) scales the default instead.\n"
        . '$n = ' . $GLOBALS['iters'] . " * \$argc;\n"
        . "if (\$argc > 1 && (int)\$argv[1] > 0) { \$n = (int)\$argv[1]; }\n"
        . "\$s = \"alpha,beta,gamma,delta,epsilon\";\n"
        . "\$acc = 0;\n"
        . "for (\$i = 0; \$i < \$n; \$i++) {\n"
        . "    \$r = \$i % 7;\n"
        . $body
        . '    $acc = $acc + ' . $expr . ";\n"
        . "}\n"
        . "echo \$acc, \"\\n\";\n";
}

/** Peak RSS in bytes of one run, via macOS `time -l` (bytes) or GNU `time -v`
 *  (kbytes). Null when neither reports it. */
function maxRssBytes(string $bin, int $dummies): ?int
{
    $cmd = \escapeshellarg($bin);
    for ($i = 0; $i < $dummies; $i++) { $cmd .= ' x'; }
    $out = (string)\shell_exec('{ /usr/bin/time -l ' . $cmd . ' >/dev/null; } 2>&1');
    if (\preg_match('/(\d+)\s+maximum resident set size/', $out, $m) === 1) {
        return (int)$m[1];
    }
    $out = (string)\shell_exec('{ /usr/bin/time -v ' . $cmd . ' >/dev/null; } 2>&1');
    if (\preg_match('/Maximum resident set size[^:]*:\s*(\d+)/', $out, $m) === 1) {
        return (int)$m[1] * 1024;
    }
    return null;
}

$dir = \sys_get_temp_dir() . '/manticore_ownership.' . \getmypid();
@\mkdir($dir, 0777, true);

\printf("%-18s %5s %10s %10s %8s  %-8s  %s\n",
    'builtin', 'class', 'rss-1x(MB)', "rss-{$scale}x", 'ratio', 'leak', 'parity');
echo \str_repeat('-', 78), "\n";

$leaks = 0; $diffs = 0; $checked = 0; $bad = [];
foreach ($T as $e) {
    $name = $e[0];
    if ($filter !== '' && !\str_contains($name, $filter)) { continue; }
    $src = $dir . '/' . $name . '.php';
    $bin = $dir . '/' . $name;
    \file_put_contents($src, probeSource($e));

    $cerr = $dir . '/' . $name . '.cerr';
    \exec(\escapeshellarg($mant) . ' compile ' . \escapeshellarg($src)
        . ' -o ' . \escapeshellarg($bin) . ' >' . \escapeshellarg($cerr) . ' 2>&1', $_, $rc);
    if ($rc !== 0) {
        \printf("%-18s %5s %10s %10s %8s  %-8s  %s\n",
            $name, $e[1], '-', '-', '-', 'COMPILE', 'see ' . $cerr);
        $bad[] = $name;
        continue;
    }

    // Parity FIRST: an over-release is a wrong answer, and a wrong answer
    // makes the RSS numbers meaningless.
    $nOut = (string)\shell_exec(\escapeshellarg($bin) . ' 2>/dev/null');
    $pOut = (string)\shell_exec(\escapeshellarg($php)
        . ' -d xdebug.mode=off ' . \escapeshellarg($src) . ' 2>/dev/null');
    $parity = \trim($nOut) === \trim($pOut) ? 'MATCH' : 'DIFF';
    if ($parity === 'DIFF') { $diffs++; $bad[] = $name; }

    $b1 = maxRssBytes($bin, 0);
    $bn = maxRssBytes($bin, $scale - 1);
    if ($b1 === null || $bn === null) {
        \printf("%-18s %5s %10s %10s %8s  %-8s  %s\n",
            $name, $e[1], '-', '-', '-', 'RSS-FAIL', $parity);
        continue;
    }
    $checked++;
    $verdict = 'ok';
    // Two points cannot tell a leak from a bounded high-water: the small-object
    // pool never returns memory, so a free list warming up grows once and then
    // plateaus. Confirm with a THIRD point at twice the scale — a leak keeps
    // paying, a high-water does not.
    if ($bn - $b1 > 524288 && $bn > 1.2 * $b1) {
        $bw = maxRssBytes($bin, $scale * 2 - 1);
        if ($bw !== null && $bw - $bn > 0.5 * ($bn - $b1)) {
            $verdict = 'LEAK';
            $leaks++;
            if ($parity !== 'DIFF') { $bad[] = $name; }
        } else {
            $verdict = 'plateau';
        }
    }
    \printf("%-18s %5s %10.1f %10.1f %8.2f  %-8s  %s\n",
        $name, $e[1], $b1 / 1048576, $bn / 1048576, $bn / $b1, $verdict, $parity);
}

echo \str_repeat('-', 78), "\n";
echo "scaled $checked · LEAK on $leaks · parity DIFF $diffs · scale {$scale}x\n";
if ($bad !== []) { echo 'to convert: ' . \implode(' ', \array_unique($bad)) . "\n"; }
if ($keep) { echo "probes kept in $dir\n"; }
else { \exec('rm -rf ' . \escapeshellarg($dir)); }
exit($leaks > 0 || $diffs > 0 ? 1 : 0);
