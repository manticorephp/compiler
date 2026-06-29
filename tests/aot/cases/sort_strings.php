<?php
// sort()/rsort() over a STRING list use the native `<`/`>` operator (strcmp),
// not a usort callback. The heapsort prelude must compare string CONTENT, not
// the element pointer — a regression renders allocation-order, not lexical.
// NOTE: keep every bare sort() call site ONE element type per program — mixing
// sort(int) and sort(string) conflicts the all-agree element inference and the
// string case erases to a pointer compare (known limitation; usort's callback
// path is immune, so int sorting lives in sort_usort_reduce).
$w = ["pear", "apple", "fig", "banana", "cherry", "apple"];
sort($w);
echo implode(",", $w), "\n";
rsort($w);
echo implode(",", $w), "\n";

// usort over assoc-array (refcounted) elements, ascending by a field.
$rows = [["n" => "z", "v" => 3], ["n" => "a", "v" => 1], ["n" => "m", "v" => 2]];
usort($rows, fn($x, $y) => $x["v"] - $y["v"]);
foreach ($rows as $r) { echo $r["n"]; }
echo "\n";
