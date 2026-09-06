<?php
/**
 * ircensus.php — where the IR bytes went. THROWAWAY diagnostic.
 *
 *   php tools/prof/ircensus.php <file.ll> [more.ll ...] [--src-bytes N] [--top N]
 *
 * symfony-demo T5 emits 2.58 GB of IR text from 26.3 MB of PHP — 98x — while
 * the compiler's own module runs about 12x. That gap is the question: is the
 * volume inherent, or is it a handful of shapes the emitter expands per SITE?
 * Counting bytes per `define` and grouping them by symbol FAMILY answers it
 * directly, which no reasoning about LLVM's verbosity can.
 *
 * Streams with fgets and keeps only per-symbol counters — the input is
 * routinely larger than RAM, so nothing here may hold a file, or a function
 * body, in memory.
 *
 * Reports:
 *   1. totals — module bytes split into defines vs everything else
 *   2. families — props fns / trampolines / mono clones / runtime / user
 *   3. the fattest individual functions
 *   4. the fattest namespace prefixes (which package is paying)
 *   5. symbols defined more than once across the inputs (split duplication)
 */

$files = [];
$srcBytes = 0;
$top = 30;
for ($i = 1; $i < $argc; $i++) {
    $a = $argv[$i];
    if ($a === '--src-bytes') { $srcBytes = (int)($argv[++$i] ?? '0'); continue; }
    if ($a === '--top') { $top = (int)($argv[++$i] ?? '30'); continue; }
    $files[] = $a;
}
if ($files === []) {
    fwrite(STDERR, "usage: php tools/prof/ircensus.php <file.ll> [...] [--src-bytes N] [--top N]\n");
    exit(2);
}

/** @var array<string,int> symbol -> body bytes */
$bySym = [];
/** @var array<string,int> symbol -> how many times defined */
$dupes = [];
/** @var array<string,array{0:int,1:int}> family -> [bytes, count] */
$fam = [];
/** @var array<string,array{0:int,1:int}> prefix -> [bytes, count] */
$pre = [];
$totalBytes = 0;
$defineBytes = 0;
$defineCount = 0;

/** The family a symbol belongs to. Order matters: the specific before the general. */
function census_family(string $sym): string
{
    if (str_starts_with($sym, '__mir_props_'))        { return 'props fn (per class)'; }
    if (str_contains($sym, '__mc_rtramp_'))           { return 'reflection trampoline'; }
    if (str_contains($sym, '__mc_refl'))              { return 'reflection'; }
    if (str_contains($sym, '$mono$'))                 { return 'monomorphised clone'; }
    if (str_starts_with($sym, '__mir_object_vars'))   { return 'erased object-vars walk'; }
    if (str_starts_with($sym, '__mir_'))              { return 'runtime helper'; }
    if (str_starts_with($sym, '__mc_'))               { return 'runtime helper'; }
    if (str_starts_with($sym, 'manticore_'))          { return 'compiled php'; }
    return 'other';
}

/** The namespace-ish prefix a compiled php symbol came from. */
function census_prefix(string $sym): string
{
    if (!str_starts_with($sym, 'manticore_')) { return ''; }
    $rest = substr($sym, strlen('manticore_'));
    $parts = explode('_', $rest);
    $n = count($parts);
    if ($n >= 3) { return $parts[0] . '_' . $parts[1] . '_' . $parts[2]; }
    if ($n >= 2) { return $parts[0] . '_' . $parts[1]; }
    return $parts[0];
}

foreach ($files as $path) {
    $fh = fopen($path, 'rb');
    if ($fh === false) { fwrite(STDERR, "cannot open $path\n"); exit(1); }
    $inDef = false;
    $sym = '';
    $bytes = 0;
    while (($line = fgets($fh)) !== false) {
        $len = strlen($line);
        $totalBytes += $len;
        if (!$inDef) {
            // `define [linkage...] <ret> @<sym>(` — take the first @name on the line.
            if (str_starts_with($line, 'define ')) {
                $at = strpos($line, '@');
                if ($at !== false) {
                    $end = strcspn($line, "( \t", $at + 1);
                    $sym = substr($line, $at + 1, $end);
                    $sym = trim($sym, '"');
                    $inDef = true;
                    $bytes = $len;
                }
            }
            continue;
        }
        $bytes += $len;
        // A body ends at a `}` in column 0.
        if ($line[0] === '}') {
            $inDef = false;
            $defineCount++;
            $defineBytes += $bytes;
            $bySym[$sym] = ($bySym[$sym] ?? 0) + $bytes;
            $dupes[$sym] = ($dupes[$sym] ?? 0) + 1;
            $f = census_family($sym);
            $fam[$f] = [($fam[$f][0] ?? 0) + $bytes, ($fam[$f][1] ?? 0) + 1];
            $p = census_prefix($sym);
            if ($p !== '') { $pre[$p] = [($pre[$p][0] ?? 0) + $bytes, ($pre[$p][1] ?? 0) + 1]; }
        }
    }
    fclose($fh);
}

function mb(int $b): string { return sprintf('%9.2f MB', $b / 1048576); }

echo "== totals ==\n";
printf("input files      %d\n", count($files));
printf("module bytes     %s\n", mb($totalBytes));
printf("in `define`      %s  (%.1f%%, %d functions)\n",
    mb($defineBytes), $totalBytes ? 100 * $defineBytes / $totalBytes : 0, $defineCount);
printf("everything else  %s  (globals, declares, metadata)\n", mb($totalBytes - $defineBytes));
if ($defineCount > 0) { printf("mean function    %9.0f bytes\n", $defineBytes / $defineCount); }
if ($srcBytes > 0) {
    printf("php source       %s\n", mb($srcBytes));
    printf("expansion        %9.1fx\n", $totalBytes / $srcBytes);
}

echo "\n== families ==\n";
uasort($fam, static fn(array $a, array $b): int => $b[0] <=> $a[0]);
printf("%-26s %14s %8s %10s %7s\n", 'family', 'bytes', 'count', 'mean', 'share');
foreach ($fam as $name => [$b, $c]) {
    printf("%-26s %14s %8d %10.0f %6.1f%%\n", $name, mb($b), $c, $c ? $b / $c : 0,
        $defineBytes ? 100 * $b / $defineBytes : 0);
}

echo "\n== fattest functions ==\n";
arsort($bySym);
$i = 0;
foreach ($bySym as $s => $b) {
    printf("%14s  %s\n", mb($b), $s);
    if (++$i >= $top) { break; }
}

echo "\n== fattest prefixes ==\n";
uasort($pre, static fn(array $a, array $b): int => $b[0] <=> $a[0]);
$i = 0;
foreach ($pre as $p => [$b, $c]) {
    printf("%14s %6d fns  %s\n", mb($b), $c, $p);
    if (++$i >= 20) { break; }
}

$dupBytes = 0;
$dupCount = 0;
foreach ($dupes as $s => $n) {
    if ($n > 1) { $dupCount++; $dupBytes += $bySym[$s] - intdiv($bySym[$s], $n); }
}
echo "\n== duplicated definitions across the inputs ==\n";
printf("%d symbols defined more than once, %s beyond the first copy\n", $dupCount, mb($dupBytes));
