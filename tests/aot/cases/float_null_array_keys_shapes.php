<?php
function nul(): ?int { return null; }
$a = [];
$k = 1.7; $n = nul(); $f2 = 2.0; $t = true;
$a[$k] = 'f'; $a[$n] = 'n'; $a[$f2] = 'two'; $a[$t] = 'T'; $a[false] = 'F';
$a[3.9] = 'lit'; $a[null] = 'nl';
var_dump($a);
var_dump($a[$k], $a[$n], isset($a[$k]), isset($a[$n]), isset($a[2.5]), array_key_exists($k, $a), array_key_exists(null, $a));
unset($a[$k]); unset($a[$n]); var_dump(array_keys($a));
$l = [1.5 => 'a', null => 'b', true => 'c', 2.0 => 'd'];
var_dump($l);
$m = ['x' => 1]; $m[$n] ??= 5; $m[$n] .= 'z'; var_dump($m);
$p = [10, 20, 30];
$h = 5 / 2;
var_dump($p[$h], $p[$h - 0.5]);
$p[$h + 0.2] = 99;
var_dump($p);
function g(float $x, array $arr) { return $arr[$x] ?? 'none'; }
echo g(1.2, [1 => 'one']), "\n";
$s = ['' => 'empty'];
$nn = nul();
echo $s[$nn], isset($s[$nn]) ? 'Y' : 'N', "\n";
$cnt = [];
foreach ([0.5, 1.5, 1.9, 2.0] as $fl) { $cnt[$fl] = ($cnt[$fl] ?? 0) + 1; }
var_dump($cnt);
