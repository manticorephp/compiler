<?php
// sort — sort() 3 K ints × 200 (heapsort on a freshly shuffled vec each round).
$total = 0;
for ($iter = 0; $iter < 200; $iter++) {
    $a = [];
    $v = $iter + 1;
    for ($i = 0; $i < 3000; $i++) {
        $v = ($v * 1103515245 + 12345) % 1000000;
        $a[$i] = $v;
    }
    sort($a);
    $total += $a[0] + $a[1499] + $a[2999];
}
echo $total, "\n";
