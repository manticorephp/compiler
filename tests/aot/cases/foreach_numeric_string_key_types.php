<?php
// A numeric string key stored through a string-typed key reads back as php's int key everywhere.

/** @return array<string,int> */
function build(int $n): array
{
    $m = [];
    for ($i = 0; $i < $n; $i++) {
        $s = (string) (10 + $i);
        $m[$s] = $i;
    }
    $m['x'] = 99;
    return $m;
}

/** @param array<string,int> $a */
function walk(array $a): void
{
    foreach ($a as $k => $v) {
        var_dump($k);
        echo is_string($k) ? 'string' : 'not-string', ' ', gettype($k), ' ', is_int($k) ? 'int' : 'not-int', "\n";
    }
}

$m = [];
foreach (['11', 'ab', '012', '-5'] as $s) {
    $m[$s] = 1;
}
foreach ($m as $k => $v) {
    var_dump($k);
    echo gettype($k), ' ', is_string($k) ? 'S' : '-', is_int($k) ? 'I' : '-', "\n";
}
var_dump(array_keys($m));
var_dump(array_search(1, $m));
var_dump(array_key_first($m), array_key_last($m));
reset($m);
var_dump(key($m));
walk(build(3));

$typed = [];
$s = '42';
$typed[$s] = 'v';
var_dump(array_search('v', $typed));
foreach ($typed as $k => $v) {
    var_dump($k + 1);
}

$base = memory_get_usage();
$peak = 0;
for ($r = 0; $r < 2000; $r++) {
    $a = [];
    for ($i = 0; $i < 1000; $i++) {
        $key = (string) $i;
        $a[$key] = $i;
    }
    $sum = 0;
    foreach ($a as $k => $v) {
        $sum += $k;
    }
    $u = memory_get_usage() - $base;
    if ($u > $peak) { $peak = $u; }
}
echo $sum, "\n";
echo $peak < 4 * 1024 * 1024 ? "flat\n" : "grows $peak\n";
$q = [];
foreach (['7', "it's", '-0', '08'] as $s) { $q[$s] = true; }
foreach ($q as $k => $_) { echo var_export($k, true), ' '; var_export($k); echo "\n"; }
final class KO { public int $a = 1; }
function mkko(int $i): mixed { return $i > 0 ? new KO() : [1]; }
foreach (['5' => 1, 'x' => 2] as $k => $v) {
    $x = $k;
    if ($v === 2) { $x = mkko(1); }
    echo var_export($x, true), "\n";
    $y = $v === 1 ? $k : mkko(0);
    echo var_export($y, true), "\n";
}
/** @param array<string,bool> $q */
function export_keys(array $q): void
{
    foreach ($q as $k => $_) { echo var_export($k, true), ' '; var_export($k); echo "\n"; }
}
export_keys($q);
foreach (['5' => 1, 'x' => 2] as $k => $v) {
    $ka = &$k;
    $ka = 1.5;
    echo var_export($k, true), "\n";
}
unset($ka);
foreach (['5' => 1, 'x' => 2] as $k => $v) {
    $k++;
    echo var_export($k, true), "\n";
}
foreach ([10 => 1, 11 => 2] as $k => $v) {
    --$k;
    echo var_export($k, true), "\n";
}
