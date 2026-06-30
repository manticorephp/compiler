<?php
// array — build then sum a 1 M-element vec (append growth + linear read).
$a = [];
for ($i = 0; $i < 1000000; $i++) {
    $a[] = $i * 2;
}
$sum = 0;
$n = count($a);
for ($i = 0; $i < $n; $i++) {
    $sum += $a[$i];
}
echo $sum, "\n";
