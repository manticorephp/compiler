<?php
// sort() over an int vec (stable merge sort), on a per-rep LCG-seeded array.
// Reps seeded from $argc.
$sum = 0;
$reps = 3000 * $argc;
for ($r = 0; $r < $reps; $r++) {
    $a = [];
    $x = $r + 1;
    for ($i = 0; $i < 500; $i++) { $x = ($x * 1103515245 + 12345) & 0x7fffffff; $a[] = $x % 100000; }
    sort($a);
    $sum += $a[0] + $a[499];
}
echo $sum, "\n";
