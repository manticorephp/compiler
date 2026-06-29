<?php
function countdown(int $n): int {
    $acc = 0;
    while ($n > 0) {
        $acc = $acc + $n;
        $n = $n - 1;
    }
    return $acc;
}
echo countdown(5);
