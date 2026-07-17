<?php
function tri(int $n): int {
    $t = 0;
    for ($i = 0; $i < $n; $i++) {
        $t += $i;
    }
    return $t;
}
echo tri(5);
