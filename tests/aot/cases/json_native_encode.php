<?php
// Native json_encode: scalars, escapes, nesting, int/string keys, bool/null.
echo json_encode(0), "\n";
echo json_encode(-42), "\n";
echo json_encode(9.5), "\n";
echo json_encode(true), json_encode(false), json_encode(null), "\n";
echo json_encode("tab\tnl\nquote\"slash\\end"), "\n";
echo json_encode([]), "\n";
echo json_encode([1, 2, 3]), "\n";
echo json_encode([[1, 2], [3, 4]]), "\n";
echo json_encode(["a" => 1, "b" => "two", "c" => [3, 4]]), "\n";
echo json_encode(["n" => null, "t" => true, "neg" => -7, "fl" => 1.25]), "\n";
echo json_encode([5 => "sparse", 10 => "keys"]), "\n";
$m = [];
for ($i = 0; $i < 40; $i++) { $m["k$i"] = $i * $i; }
echo json_encode($m), "\n";
