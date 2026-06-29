<?php
// Bare sort()/rsort() over DIFFERENT element types in one program. The by-ref
// prelude sort is monomorphised per element type (Monomorphize now specializes
// by-ref params) — without it the all-agree call-site inference erases the
// element and the string case does a pointer compare.
$s = ["pear", "apple", "fig", "cherry"];
sort($s);
echo implode(",", $s), "\n";

$i = [3, 1, 2, 9, 5];
sort($i);
echo implode(",", $i), "\n";

$f = [4.5, 1.1, 9.9, 2.2];
sort($f);
echo implode(",", $f), "\n";

$r = ["z", "a", "m"];
rsort($r);
echo implode(",", $r), "\n";

$ri = [5, 8, 1];
rsort($ri);
echo implode(",", $ri), "\n";
