<?php

// similar_text()'s third parameter is a by-REFERENCE float carrying the
// similarity as a percentage: sim * 200 / (strlen($a) + strlen($b)).
//
// php computes it ONCE, from the outer pair of lengths. The character count
// itself is recursive (longest common substring, then the same over the
// segments either side of it), so the recursion has to live in a worker that
// knows nothing about the percentage — threading &$percent through it would
// leave whichever segment returned last in the caller's variable.

$n = similar_text("World", "word", $pct);
var_dump($n, $pct);

$n2 = similar_text("Hello", "Hello", $p2);
var_dump($n2, $p2);

$n3 = similar_text("abc", "xyz", $p3);
var_dump($n3, $p3);

// both empty: php divides by nothing and leaves 0.0 rather than a NaN
$n4 = similar_text("", "", $p4);
var_dump($n4, $p4);

$p5 = 99.5;
$n5 = similar_text("Manticore", "Manticorp", $p5);
var_dump($n5, $p5);

// omitted entirely — the count is the return value on its own
var_dump(similar_text("World", "word"));
