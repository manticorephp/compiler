<?php
// Strict === / !== between a known string and a mixed/cell value: equal iff the
// cell holds a string with matching bytes (a non-string cell is never ===).
function eqHi(mixed $m): bool { return "hi" === $m; }
function neHi(mixed $m): bool { return "hi" !== $m; }
var_dump(eqHi("hi"));
var_dump(eqHi("bye"));
var_dump(eqHi(5));
var_dump(eqHi(null));
var_dump(neHi("hi"));
var_dump(neHi("x"));

// "5" === 5 is false (strict: different types); "5" === "5" true.
function isStrFive(mixed $m): bool { return "5" === $m; }
var_dump(isStrFive(5));
var_dump(isStrFive("5"));

// Lookup by mixed needle over a typed value list.
/** @param string[] $list */
function indexOf(array $list, mixed $needle): int {
    foreach ($list as $i => $v) { if ($v === $needle) { return $i; } }
    return -1;
}
echo indexOf(["a", "b", "c"], "b"), "\n";
echo indexOf(["a", "b", "c"], "z"), "\n";
