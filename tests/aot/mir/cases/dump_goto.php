<?php
function g(int $x): int {
    $t = 0;
    if ($x > 0) {
        goto done;
    }
    $t = 99;
done:
    return $t;
}
echo g(1);
