<?php
function k(mixed $v): string { return (string)$v; }
function probe(array $h, string $s): void {
    echo var_export(isset($h[$s]), true), ' ',
        var_export($h[$s] ?? 'miss', true), ' ',
        var_export(array_key_exists($s, $h), true), ' ',
        var_export(@$h[$s], true), "\n";
}
$h = [5 => 'x', 0 => 'z', -7 => 'neg', 'a' => 'str'];
foreach (['5', '05', ' 5', '-0', '0', '-7', '-07', '5.0', 'a', '9223372036854775808'] as $i => $lit) {
    $s = k($lit);
    echo $lit, ': ';
    probe($h, $s);
}
$big = [];
for ($i = 0; $i < 40; $i++) { $big[$i * 3] = "v$i"; }
foreach (['0', '3', '117', '118', '03'] as $lit) {
    $s = k($lit);
    echo $lit, ': ';
    probe($big, $s);
}
$list = [10, 20, 30];
$s = k('1');
echo $list[$s], ' ', isset($list[$s]) ? 'set' : 'unset', ' ', var_export(array_key_exists($s, $list), true), "\n";
unset($list[$s]);
var_dump(count($list), isset($list[1]), $list[2]);
$h2 = [5 => 'x', 6 => 'y', 'a' => 1];
foreach (['5', '05', ' 6', 'a'] as $lit) {
    $s = k($lit);
    unset($h2[$s]);
    echo $lit, ' => ', json_encode($h2), "\n";
}
$big2 = [];
for ($i = 0; $i < 40; $i++) { $big2[$i] = $i; }
$s = k('7');
unset($big2[$s]);
var_dump(count($big2), isset($big2[7]), isset($big2[$s]), array_key_exists($s, $big2));
