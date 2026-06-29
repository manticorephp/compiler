<?php
function cmp(int $a, int $b): bool {
    $lt = $a < $b;
    $eq = $a === $b;
    $gt = $a > $b;
    return $lt;
}
echo cmp(1, 2);
