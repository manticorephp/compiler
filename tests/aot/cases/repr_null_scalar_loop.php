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

// null-seeded SELF-REFERENTIAL INT accumulator. `unknown + int` is unknown, so the
// body types UNKNOWN and used to take the raw ptr-0 path — where null is
// indistinguishable from a real 0, so the empty case returned int(0) not NULL.
// The arith-use marker routes it to the tagged-cell promotion instead.
function sumInt(array $xs): mixed {
    $acc = null;
    foreach ($xs as $x) { $acc = $acc === null ? $x : $acc + $x; }
    return $acc;
}

function prodInt(array $xs): mixed {
    $acc = null;
    foreach ($xs as $x) { $acc = $acc === null ? $x : $acc * $x; }
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
foreach ([[1, 2, 3], [5], []] as $xs) {
    $r = sumInt($xs);
    echo gettype($r), " ", var_export($r, true), "\n";
}
foreach ([[2, 3, 4], []] as $xs) {
    $r = prodInt($xs);
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
