<?php
// Generic-body identity: of the shareable (linkonce_odr, program-independent by
// name) definitions present in two or more LLVM IR modules, how many bytes are
// byte-identical across all of them. No normalization of any kind: a body that
// differs by one token is a root cause to fix, not something to hash around.
//
//   php tools/generic_identity.php [--diffs N] a.ll b.ll ...
//
// Prints `generic-identical: <pct>% of <bytes> bytes (<n> functions, <d> differ)`
// and, for up to N differing functions (default 40), the first differing line pair.

declare(strict_types=1);

$maxDiffs = 40;
$files = [];
for ($i = 1; $i < $argc; $i++) {
    if ($argv[$i] === '--diffs') {
        $maxDiffs = (int)($argv[++$i] ?? '40');
        continue;
    }
    $files[] = $argv[$i];
}
if (count($files) < 2) {
    fwrite(STDERR, "usage: php tools/generic_identity.php [--diffs N] a.ll b.ll ...\n");
    exit(2);
}

/** @return array<string, string> name => full definition text */
function shareable_defs(string $path): array
{
    $text = file_get_contents($path);
    if ($text === false) {
        fwrite(STDERR, "cannot read $path\n");
        exit(2);
    }
    $out = [];
    if (preg_match_all('/^define linkonce_odr [^\n]*?@("(?:[^"\\\\]|\\\\.)*"|[\w.$\\\\-]+)\(.*?\{\n.*?^\}\n/ms', $text, $m, PREG_SET_ORDER) === false) {
        return $out;
    }
    foreach ($m as $def) {
        $name = trim($def[1], '"');
        if (str_contains($name, '$mono$') || preg_match('/(^|_)__closure_/', $name) === 1
            || preg_match('/_m[0-9a-f]{16}$/', $name) === 1) {
            continue;
        }
        $out[$name] = $def[0];
    }
    return $out;
}

/** @var array<string, array<string, string>> name => (file => text) */
$byName = [];
foreach ($files as $f) {
    foreach (shareable_defs($f) as $name => $text) {
        $byName[$name][$f] = $text;
    }
}

$total = 0;
$same = 0;
$n = 0;
/** @var array<string, array{int, string, string, string}> */
$diffs = [];
foreach ($byName as $name => $copies) {
    if (count($copies) < 2) {
        continue;
    }
    $n++;
    $bytes = 0;
    foreach ($copies as $t) {
        $bytes += strlen($t);
    }
    $total += $bytes;
    if (count(array_unique($copies)) === 1) {
        $same += $bytes;
        continue;
    }
    $vals = array_values($copies);
    $a = explode("\n", $vals[0]);
    $b = [];
    foreach ($vals as $v) {
        if ($v !== $vals[0]) {
            $b = explode("\n", $v);
            break;
        }
    }
    $la = '';
    $lb = '';
    foreach ($a as $k => $line) {
        if ($line !== ($b[$k] ?? null)) {
            $la = trim($line);
            $lb = trim($b[$k] ?? '<end>');
            break;
        }
    }
    $diffs[$name] = [$bytes, $la, $lb, (string)count(array_unique($copies))];
}

uasort($diffs, fn(array $x, array $y): int => $y[0] <=> $x[0]);
$pct = $total === 0 ? 100.0 : 100.0 * $same / $total;
printf("generic-identical: %.1f%% of %d bytes (%d functions, %d differ)\n", $pct, $total, $n, count($diffs));
$shown = 0;
foreach ($diffs as $name => [$bytes, $la, $lb, $variants]) {
    if ($shown++ >= $maxDiffs) {
        break;
    }
    printf("diff %s  bytes=%d variants=%s\n  A: %s\n  B: %s\n", $name, $bytes, $variants, substr($la, 0, 160), substr($lb, 0, 160));
}
