<?php
function show(array $a): void { echo json_encode($a), "\n"; }
function all(array $a): void {
    $b = $a; ksort($b); show($b);
    $b = $a; krsort($b); show($b);
    $b = $a; asort($b); show($b);
    $b = $a; arsort($b); show($b);
}
all([]);
all(["a" => 1]);
all(["b" => 2, "a" => 1]);
all(["k10" => 3, "k9" => 3, "k2" => 1, "k1" => 3, "k3" => 2]);          // stable ties
all(["x" => "pear", "y" => "apple", "z" => "pear", "w" => "fig", "v" => "apple"]);
all([5 => "e", 3 => "c", 9 => "i", 1 => "a", 7 => "g"]);                 // int keys, string values
all([5 => 50, 3 => 30, 9 => 90, 1 => 10, 7 => 70, 2 => 30]);
all([-5 => 1, 3 => 0, -9 => 2, 0 => 5]);                                  // negative int keys
all(["10" => "a", "9" => "b", "100" => "c"]);                             // canonical numeric keys are ints
all(["1.5" => 1, "1e1" => 2, "9" => 3, "abc" => 4]);                       // numeric-looking string keys
all(["a" => 1, 5 => 2, "b" => 3, 2 => 4]);                                // mixed int/string keys
all(["a" => 1, "b" => 2.5, "c" => "x", "d" => true, "e" => null]);        // mixed value types
all(["a" => 1.5, "b" => 0.5, "c" => 1.5]);                                // floats
all(["a" => "", "b" => "a", "c" => "", "d" => "A"]);                      // empty strings, case
// after unset (tombstones)
$t = ["a" => 3, "b" => 1, "c" => 2, "d" => 0, "e" => 5];
unset($t["b"], $t["d"]);
all($t);
// larger than one merge pass, with duplicates
$big = [];
for ($i = 0; $i < 40; $i++) { $big["k" . (($i * 7) % 13) . "_" . $i] = ($i * 5) % 11; }
all($big);
$bigI = [];
for ($i = 0; $i < 37; $i++) { $bigI[($i * 11) % 41] = ($i * 3) % 7; }
all($bigI);
// copy-on-write: the sorted copy must not disturb the original
$orig = ["z" => 1, "y" => 2, "x" => 3];
$copy = $orig; ksort($copy);
show($orig); show($copy);
// flags still route through the generic path
$f = ["b" => "10", "a" => "9", "c" => "1e1"];
$g = $f; asort($g, SORT_STRING); show($g);
$g = $f; asort($g, SORT_NUMERIC); show($g);
$g = ["img12" => 1, "img10" => 2, "IMG2" => 3, "img1" => 4];
ksort($g, SORT_NATURAL | SORT_FLAG_CASE); show($g);
$g = ["B" => 1, "a" => 2, "C" => 3];
ksort($g, SORT_STRING | SORT_FLAG_CASE); show($g);
krsort($g, SORT_STRING); show($g);
// the next free index survives a sort
$n = [3 => "c", 1 => "a", 2 => "b"];
ksort($n); $n[] = "d"; show($n);
// lists
$l = [3, 1, 2];
asort($l); show($l); arsort($l); show($l);
$l = ["b", "a"]; ksort($l); show($l); krsort($l); show($l);
