<?php
// foreach over a non-array must be a no-op (php warns to stderr + skips), not a
// fault. Covers: a missing key probed to null, literal null, and a `mixed`
// param holding null / array / scalar. Regression for the cell-iterand crash.
$a = ["x" => 1];
$out = [];
foreach ($a["missing"] ?? null as $e) { $out[] = $e; }   // missing key -> null (a bare read throws, #718)
echo "undef: ", count($out), "\n";

$n = null;
foreach ($n as $e) { echo $e; }                  // literal null
echo "null done\n";

function pick(mixed $v): array { $r = []; foreach ($v as $x) { $r[] = $x; } return $r; }
echo "mixed-null: ", count(pick(null)), "\n";
echo "mixed-array: ", count(pick([1, 2, 3])), "\n";
echo "mixed-str: ", count(pick("str")), "\n";
