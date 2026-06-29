<?php
// A MIXED-key array — both literal string keys AND a literal int key — must ride
// a tagged cell key end-to-end (key_cell_at boxes each entry by its KIND). Before
// the fix it typed assoc[string], so foreach read the int key as a string ptr →
// SIGSEGV, and a later read printed the key cell's raw NaN-boxed bits.
$m = [];
$m["a"] = 1;
$m[5] = 2;
$m["b"] = 3;
foreach ($m as $k => $v) { echo $k, "=", $v, "\n"; }

// int key first, mixed value types
$n = [];
$n[10] = "ten";
$n["x"] = 99;
$n[20] = "twenty";
foreach ($n as $k => $v) { echo $k, "=", $v, "\n"; }
echo "x=", $n["x"], " 10=", $n[10], " count=", count($n), "\n";

// pure-string and pure-int keys must be unaffected (no cell promotion)
$s = ["a" => 1, "b" => 2];
foreach ($s as $k => $v) { echo $k, ":", $v, " "; }
echo "\n";
$i = [5 => "f", 6 => "g"];
foreach ($i as $k => $v) { echo $k, ":", $v, " "; }
echo "\n";
