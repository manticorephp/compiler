<?php
// Every json_encode flag, against the oracle. Before this, a second argument
// did two wrong things at once: the flag was dropped, and the call left the
// native single-argument builtin for the compiled-PHP encoder — whose escaper
// was itself shadowed by a codegen builtin that passes `/` and non-ASCII
// through raw, so the fallback behaved as if UNESCAPED_SLASHES|UNESCAPED_UNICODE
// were always on.

$s = "<a>&'\" / \xc3\xa9\xe2\x82\xac";

echo json_encode($s), "\n";
echo json_encode($s, JSON_HEX_TAG), "\n";
echo json_encode($s, JSON_HEX_AMP), "\n";
echo json_encode($s, JSON_HEX_APOS), "\n";
echo json_encode($s, JSON_HEX_QUOT), "\n";
echo json_encode($s, JSON_UNESCAPED_SLASHES), "\n";
echo json_encode($s, JSON_UNESCAPED_UNICODE), "\n";
echo json_encode($s, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
echo json_encode($s, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), "\n";

echo json_encode([1, 2], JSON_FORCE_OBJECT), "\n";
echo json_encode(["12", "1.5", "x", "012"], JSON_NUMERIC_CHECK), "\n";
echo json_encode([1.0, 2.5, 3.0], JSON_PRESERVE_ZERO_FRACTION), "\n";
echo json_encode([1.0, 2.5, 3.0]), "\n";

echo json_encode(["a" => 1, "b" => [1, 2], "c" => [], "d" => ["x" => "y"]], JSON_PRETTY_PRINT), "\n";
echo json_encode([], JSON_PRETTY_PRINT), "\n";
echo json_encode([1, [2, [3]]], JSON_PRETTY_PRINT), "\n";
echo json_encode(["k" => "a/b"], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";

// The flag arrives in a variable, not as a folded constant.
$f = JSON_UNESCAPED_SLASHES;
echo json_encode("p/q", $f), "\n";

// A key is escaped by the same rules as a value.
echo json_encode(["a/b" => 1, "\xc3\xa9" => 2]), "\n";
echo json_encode(["a/b" => 1, "\xc3\xa9" => 2], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
