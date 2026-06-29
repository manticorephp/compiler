<?php
function grow(int $n): int {
    $a = [0];              // confined vec, grown -> Arena
    $a[] = $n;
    return $a[1];
}
function aliased(int $n): int {
    $xs = [1, 2];          // aliased -> RcHeap (not Arena)
    $ys = $xs;
    $ys[] = 3;
    return $xs[0];
}
echo grow(7);
echo aliased(0);
