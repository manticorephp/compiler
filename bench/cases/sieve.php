<?php
// sieve — Sieve of Eratosthenes to 2 M (bool vec, nested marking loop).
$n = 2000000;
$is = [];
for ($i = 0; $i <= $n; $i++) { $is[$i] = true; }
$is[0] = false; $is[1] = false;
$count = 0;
for ($i = 2; $i <= $n; $i++) {
    if ($is[$i]) {
        $count++;
        for ($j = $i + $i; $j <= $n; $j += $i) { $is[$j] = false; }
    }
}
echo $count, "\n";
