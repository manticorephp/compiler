<?php

/**
 * Differential harness: __McTok vs Zend's C tokenizer, IN THE SAME INTERPRETER.
 *
 * prelude/tokenizer.php names nothing Zend owns, so it can simply be required
 * here. A divergence this tool reports is an ALGORITHM bug in the scanner. A
 * divergence that appears only in a COMPILED binary running the same input is a
 * COMPILER bug. Keeping those two apart is the whole reason the scanner core and
 * the ext/tokenizer API live in separate files.
 *
 *   php tools/tokenizer_diff.php src prelude      # sweep directories
 *   php tools/tokenizer_diff.php path/to/one.php  # one file, verbose
 */

require __DIR__ . '/../prelude/tokenizer.php';

/** Canonical, whitespace-visible dump of Zend's token stream. */
function zend_dump(string $src): array
{
    $out = [];
    foreach (token_get_all($src) as $t) {
        if (is_string($t)) { $out[] = 'CHAR|' . $t; continue; }
        $out[] = token_name($t[0]) . '|' . $t[2] . '|' . addcslashes($t[1], "\0..\37\\");
    }
    return $out;
}

/** The same dump, from our scanner's packed arrays. */
function mc_dump(string $src): array
{
    $t = __McTok::run($src, 0, 1);
    $names = __mc_tok_names();
    $out = [];
    $i = 0;
    while ($i < $t->n) {
        $m = $t->meta[$i];
        $id = $m & 4095;
        if ($id < 256) { $out[] = 'CHAR|' . $t->texts[$i]; $i++; continue; }
        $out[] = ($names[$id] ?? "ID$id") . '|' . ($m >> 12) . '|'
               . addcslashes($t->texts[$i], "\0..\37\\");
        $i++;
    }
    return $out;
}

function firstDivergence(array $a, array $b): int
{
    $n = min(count($a), count($b));
    for ($i = 0; $i < $n; $i++) {
        if ($a[$i] !== $b[$i]) { return $i; }
    }
    return count($a) === count($b) ? -1 : $n;
}

$targets = array_slice($argv, 1);
if ($targets === []) { $targets = ['src', 'prelude']; }

$files = [];
foreach ($targets as $t) {
    $p = $t[0] === '/' ? $t : __DIR__ . '/../' . $t;
    if (is_file($p)) { $files[] = $p; continue; }
    if (!is_dir($p)) { fwrite(STDERR, "no such path: $t\n"); exit(1); }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($p));
    foreach ($it as $f) {
        if ($f->isFile() && $f->getExtension() === 'php') { $files[] = $f->getPathname(); }
    }
}
sort($files);

$verbose = count($files) === 1;
$match = 0;
$diff = 0;
$shown = 0;
$tokens = 0;

foreach ($files as $f) {
    $src = file_get_contents($f);
    if ($src === false) { continue; }
    $z = zend_dump($src);
    $m = mc_dump($src);
    $tokens += count($z);
    $at = firstDivergence($z, $m);
    if ($at === -1) { $match++; continue; }
    $diff++;
    if (!$verbose && $shown >= 10) { continue; }
    $shown++;
    $rel = str_replace(__DIR__ . '/../', '', $f);
    echo "DIFF $rel @token $at (zend " . count($z) . " tokens, mc " . count($m) . ")\n";
    for ($k = max(0, $at - 2); $k < min(max(count($z), count($m)), $at + 3); $k++) {
        $mark = $k === $at ? ' <<<' : '';
        echo '  [' . $k . "] zend: " . ($z[$k] ?? '(none)') . "\n";
        echo '  [' . $k . "] mc  : " . ($m[$k] ?? '(none)') . $mark . "\n";
    }
    echo "\n";
}

echo "\n" . $match . ' MATCH / ' . $diff . ' DIFF over ' . count($files)
   . ' files, ' . $tokens . " zend tokens\n";
exit($diff === 0 ? 0 : 1);
