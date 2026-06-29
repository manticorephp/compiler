<?php
function frees(int $n, string $borrowed): void {
    $s = "tmp" . $n;        // confined string -> mem_release
    $a = [$n, $n];          // confined vec    -> mem_release
    $b = $borrowed;         // borrow          -> NO release
    echo $s;
    echo $a[0];
    echo $b;
}
frees(1, "x");
