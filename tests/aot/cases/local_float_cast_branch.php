<?php
// `$v = (float)$v` on one path converts only there: the int the slot held on
// the other path is still an int (the float-slot promotion is for accumulators).
function gl(): int { return 1234; }
function a(int $t): int|float { $v = gl(); if ($t === 7) { $v = (float)$v; } return $v; }
function b(int $t): int|float|null
{
    $v = null;
    if ($t > 0) {
        $v = gl();
        if ($v === PHP_INT_MIN) { $v = (float)$v; }
    }
    return $v;
}
var_dump(a(5), a(7), b(1), b(0));
