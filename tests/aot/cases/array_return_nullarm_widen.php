<?php
// A `: array` function that builds a null|string accumulator returns vec[cell].
// The early NarrowReturns pass could lock a premature vec[string] from a
// pre-convergence view, so a caller read the box_null cells as raw string
// pointers → var_dump SIGSEGV. Only triggers when another function shares the
// module (whole-module inference convergence), so keep a sibling function here.

function sibling(string $h, string $n): ?string {
    $p = strpos($h, $n);
    return $p === false ? null : substr($h, $p);
}

function rows(): array {
    $r = [];
    foreach (["a", "", "c"] as $s) {
        $r[] = ($s === "") ? null : $s;
    }
    return $r;
}

var_dump(sibling("hello", "zz"));
var_dump(sibling("hello", "ll"));

$rw = rows();
var_dump($rw);
foreach ($rw as $x) { echo ($x ?? "NULL"), "|"; }
echo "\n";
var_dump($rw[1] === null);

// a second array-returning fn with an int|null accumulator
function nums(): array {
    $o = [];
    foreach ([1, 2, 3] as $i) { $o[] = ($i === 2) ? null : $i; }
    return $o;
}
$nn = nums();
var_dump($nn);
var_dump($nn[1]);
echo ($nn[1] ?? -1), "\n";
