<?php
// Direct-store array literals: the emitter writes a fully-known literal (all
// unkeyed, or all distinct string-const keys) straight into the buffer instead
// of routing each element through set_int / set_str. These exercise the shapes
// that path handles and the ones it must decline.

// packed list — direct
$a = [1, 2, 3];
$a[] = 4;               // append must continue from index 3, not 0
echo implode(",", $a), "\n";        // 1,2,3,4
echo count($a), " ", $a[0], " ", $a[3], "\n";

// hashed, distinct string-const keys — direct
$b = ["id" => 7, "name" => "widget", "price" => 9.5, "tags" => ["x", "y"]];
echo $b["id"], " ", $b["name"], " ", $b["price"], " ", count($b["tags"]), "\n";
$b["extra"] = true;     // grow a direct-built hashed array
echo count($b), " ", ($b["extra"] ? "y" : "n"), "\n";

// mixed value types in one literal
$c = ["i" => 1, "f" => 2.5, "s" => "z", "b" => false, "n" => null, "arr" => [1, 2]];
echo $c["i"], $c["f"], $c["s"], ($c["b"] ? 1 : 0), " ", ($c["n"] === null ? "null" : "x"), " ", count($c["arr"]), "\n";

// duplicate string key — LAST wins; must NOT take the direct path
$d = ["k" => 1, "k" => 2, "j" => 3];
echo $d["k"], " ", $d["j"], " ", count($d), "\n";     // 2 3 2

// int keys — not the string-hashed direct path (stays generic)
$e = [5 => "a", 9 => "b"];
echo $e[5], $e[9], " ", count($e), "\n";

// spread — declines direct
$base = [1, 2];
$f = [0, ...$base, 3];
echo implode(",", $f), "\n";        // 0,1,2,3

// empty
$g = [];
$g[] = "first";
echo count($g), " ", $g[0], "\n";

// nested direct literals (both levels take the direct path)
$h = ["outer" => ["a" => 1, "b" => 2], "list" => [10, 20, 30]];
echo $h["outer"]["a"], $h["outer"]["b"], " ", $h["list"][2], "\n";

// value-copy semantics survive: $b was assigned into $b2, mutating one must
// not disturb the other (the direct path still produces a normal rc'd array)
$b2 = $b;
$b2["id"] = 99;
echo $b["id"], " ", $b2["id"], "\n";        // 7 99
