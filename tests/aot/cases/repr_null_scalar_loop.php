<?php

// null-seeded accumulator, string body (pointer — nullLoopLocals path)
function joinStr(array $xs): mixed {
    $acc = null;
    foreach ($xs as $x) { $acc = $acc === null ? $x : $acc . "," . $x; }
    return $acc;
}

// null-seeded accumulator, float self-referential body (cell promote path)
function sumFloat(array $xs): mixed {
    $acc = null;
    foreach ($xs as $x) { $acc = $acc === null ? $x : $acc + $x; }
    return $acc;
}

// null flips to an int scalar unconditionally inside the loop, read after —
// the "$x = null; loop { $x = <int> }" case (clean numeric null-loop).
function lastSeen(array $xs): mixed {
    $seen = null;
    foreach ($xs as $x) { $seen = $x * 2; }
    return $seen;
}

// null flips to a bool unconditionally inside the loop.
function anyTrue(array $xs): mixed {
    $hit = null;
    foreach ($xs as $x) { $hit = $x > 0; }
    return $hit;
}

foreach ([['a','b','c'], []] as $xs) {
    $r = joinStr($xs);
    echo gettype($r), " ", var_export($r, true), "\n";
}
foreach ([[1.5, 2.5], []] as $xs) {
    $r = sumFloat($xs);
    echo gettype($r), " ", var_export($r, true), "\n";
}
foreach ([[4, 5], []] as $xs) {
    $r = lastSeen($xs);
    echo gettype($r), " ", var_export($r, true), "\n";
}
foreach ([[1, -1], []] as $xs) {
    $r = anyTrue($xs);
    echo gettype($r), " ", var_export($r, true), "\n";
}
