<?php
// PHP truthiness of a string: "" and "0" are FALSY, every other string truthy.
// A condition on a string must honour this (not just a null-pointer check).
function elvis(string $s): string { return $s ?: "def"; }
echo elvis(""), " ", elvis("0"), " ", elvis("x"), " ", elvis("00"), "\n";

function truthy(string $s): string { if ($s) { return "T"; } return "F"; }
echo truthy(""), truthy("0"), truthy("a"), truthy(" "), truthy("false"), "\n";

function negate(string $s): string { return !$s ? "empty" : "full"; }
echo negate(""), " ", negate("0"), " ", negate("hi"), "\n";

// `&&` / `||` short-circuit over string operands.
$a = ""; $b = "yes";
echo ($a && $b) ? "both" : "not", " ", ($a || $b) ? "either" : "none", "\n";

// while over a shrinking string.
$s = "abc"; $n = 0;
while ($s) { $s = substr($s, 1); $n++; }
echo $n, "\n";

// Array truthiness: `[]` is falsy, a non-empty array truthy.
$empty = []; $full = [1, 2];
echo ($empty ?: "e0") === "e0" ? "e0" : "ne", " ", (($full ?: []) === $full ? "keep" : "no"), "\n";
echo ($empty ? "T" : "F"), ($full ? "T" : "F"), "\n";
