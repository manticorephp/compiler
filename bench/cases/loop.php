<?php
// loop — 50 M-iteration integer accumulate (pure codegen, no allocation).
$sum = 0;
for ($i = 0; $i < 50000000; $i++) {
    $sum += $i & 7;
}
echo $sum, "\n";
