<?php
// A mixed (cell) string bound to a builtin's string position is unboxed, never read as a raw pointer.
$p = ["ab", "%s!"];
array_splice($p, 0, 0, ["z"]);
$c = $p[1][1];
$f = $p[2];
echo strpos("ba", $c), "|", stripos("BA", $c), "|", strpos("bab", $c, 1), "|", stripos("BAB", $c, -1), "\n";
var_dump(strpos("xyz", $c), stripos("xyz", $c, 1));
echo strrpos("bab", $c), "|", strripos("BAB", $c), "|", str_replace($c, "X", "abc"), "\n";
echo sprintf($f, $c), "|", sprintf($f, ...[$c]), "|", vsprintf($f, [$c]), "\n";
printf($f, ...["q"]); echo "\n";
echo count($p), "\n";
