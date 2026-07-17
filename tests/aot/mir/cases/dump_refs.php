<?php
function bump(array &$a): void {
    $r = &$a[0];
    $r = $r + 100;
}
function &firstOf(array &$a): int {
    return $a[1];
}
function refs(): int {
    $x = 5;
    $y = &$x;
    $y = 10;
    $arr = [1, 2];
    bump($arr);
    $r = &firstOf($arr);
    $r = 50;
    return $x + $arr[0] + $arr[1];
}
echo refs();
