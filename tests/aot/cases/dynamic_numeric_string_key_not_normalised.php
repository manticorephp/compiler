<?php
// A dynamic numeric-string array key is canonicalised like php's: $h[$s] with $s="5" is stored as int 5
$s = '5';
$h = [];
$h[$s] = 1;
var_dump(array_keys($h));
var_dump($h[5] ?? 'miss');
var_dump(isset($h[5]));
function refs(string $s) {
    $a = []; $r = &$a[$s]; $r = 7; echo json_encode($a), "\n";
    $b = [5 => 1, '05' => 2, 'x' => 3]; $q = &$b[$s]; $q = 9; echo json_encode($b), "\n";
    $c = ['a' => 1]; $z = &$c[$s]; $z = 'n'; echo json_encode($c), "\n";
}
refs('5'); refs('05'); refs('-0'); refs('x'); refs('-12');
$m = [];
foreach (['3', '4', 'k', '03'] as $k) { $m[$k] = $k; }
foreach ($m as $k => &$v) { $v = "$v!"; }
unset($v);
var_dump($m);
