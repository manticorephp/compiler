<?php
// ksort_asort — key/value sorts on a 10k map (quadratic insertion-sort path today).
// Duplicate values make asort/arsort stability observable (PHP 8 sorts are stable).
$m = [];
$x = 123456789;
for ($i = 0; $i < 10000; $i++) {
    $x = ($x * 1103515245 + 12345) % 2147483648;
    $m["k" . $x . "_" . $i] = $x % 1000;
}
ksort($m);
asort($m);
arsort($m);
$chk = 0;
foreach ($m as $k => $v) { $chk = ($chk * 31 + $v + strlen($k)) % 1000000007; }
echo count($m), " ", $chk, "\n";
