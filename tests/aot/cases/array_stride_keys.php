<?php
foreach ([1, 7, 64, 1024, 4096] as $st) {
    $a = [];
    for ($i = 0; $i < 4096; $i++) { $a[$i * $st + 1] = $i; }
    $sum = 0;
    for ($i = 0; $i < 4096; $i++) { $sum += $a[$i * $st + 1] * ($i % 13 + 1); }
    for ($i = 0; $i < 4096; $i += 2) { unset($a[$i * $st + 1]); }
    $miss = 0;
    for ($i = 0; $i < 4096; $i++) { if (!isset($a[$i * $st + 1])) { $miss++; } }
    for ($i = 0; $i < 4096; $i += 2) { $a[$i * $st + 1] = $i + 5; }
    $sum2 = 0;
    for ($i = 0; $i < 4096; $i++) { $sum2 += $a[$i * $st + 1] * ($i % 7 + 1); }
    echo $st, " ", count($a), " ", $sum, " ", $miss, " ", $sum2, "\n";
}
