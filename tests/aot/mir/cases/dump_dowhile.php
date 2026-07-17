<?php
function countdown(int $n): int {
    $t = 0;
    do {
        $t += $n;
        $n--;
    } while ($n > 0);
    return $t;
}
echo countdown(4);
