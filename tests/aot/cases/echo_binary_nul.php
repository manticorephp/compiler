<?php

// echo is binary-safe: a NUL is a byte like any other, not a terminator.
$bin = "ab\x00cd";
echo $bin, "\n";
echo strlen($bin), "\n";

// Same value reached through a `mixed` element, so it arrives NaN-boxed and
// renders via the tagged-echo helper rather than the direct string arm.
$mixed = [1, $bin, 2.5, true, null];
foreach ($mixed as $v) {
    echo $v;
    echo "|";
}
echo "\n";

// Concat keeps the embedded NUL too, and the result still echoes whole.
$j = $bin . "\x00tail";
echo $j, "\n";
echo strlen($j), "\n";
