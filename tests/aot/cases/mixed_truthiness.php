<?php
// Boolean coercion of a mixed/cell value must unbox by tag (a boxed 0/false/""
// has non-zero raw bits → would read truthy).
function t(mixed $x): string { return $x ? "T" : "F"; }
echo t(0), t(1), t(-1), t(""), t("0"), t("00"), t("hi"), t(0.0), t(3.2), t([]), t([1]), t(true), t(false), "\n";

function bang(mixed $x): string { return !$x ? "n" : "y"; }
echo bang(0), bang("x"), bang(""), "\n";

// && / || short-circuit on mixed
function side(mixed $n): mixed { echo "S"; return $n; }
$r = side(0) && side(1); echo "/", ($r ? "Y" : "N"), "\n";
$q = side(0) || side(7); echo "/", ($q ? "Y" : "N"), "\n";

// while on a mixed counter
function down(mixed $n): int { $c = 0; while ($n) { $c = $c + 1; $n = $n - 1; } return $c; }
echo down(3), "\n";
