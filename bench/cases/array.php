<?php
// array — build then sum a 500 K-element vec, repeated (append growth + linear
// read). Values depend on $argc and the sum is a data-dependent chain so neither
// the build nor the read folds; the array is rebuilt each rep so memory is bounded.
$seed = $argc;
$sum = 0;
for ($rep = 0; $rep < 30; $rep++) {
    $a = [];
    for ($i = 0; $i < 500000; $i++) {
        $a[] = ($i * $seed + $rep) & 0xFFFF;
    }
    $n = count($a);
    for ($i = 0; $i < $n; $i++) {
        $sum = ($sum + $a[$i]) ^ ($sum & 1);
    }
}
echo $sum, "\n";
