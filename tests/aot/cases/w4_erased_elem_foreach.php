<?php
$k = ['ab', 'cde', 'f'];
$m = array_combine($k, array_map('strlen', $k));
foreach ($m as $name => $len) {
    var_dump($len);
    var_dump($len + 1);
}
$big = array_map(fn($s) => strlen($s) * 100000, $k);
foreach ($big as $v) { var_dump($v); }
$sum = 0;
foreach ($big as $v) { $sum += $v; }
var_dump($sum);
var_dump(array_sum($big));
