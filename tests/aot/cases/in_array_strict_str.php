<?php
// Strict in_array of a string over a string vec: hit/miss, lengths, NUL bytes, hashed values, temporaries.
$h = [];
for ($i = 0; $i < 20; $i++) { $h[] = "item" . $i; }
$h[] = "";
$h[] = "a\0b";
$h[] = "a\0c";

var_dump(in_array("item0", $h, true));
var_dump(in_array("item19", $h, true));
var_dump(in_array("item20", $h, true));
var_dump(in_array("item1", $h, true));
var_dump(in_array("item", $h, true));
var_dump(in_array("", $h, true));
var_dump(in_array("a\0b", $h, true));
var_dump(in_array("a\0d", $h, true));
var_dump(in_array("a", $h, true));
var_dump(in_array("ITEM1", $h, true));

for ($i = 0; $i < 25; $i++) {
    if (in_array("item" . $i, $h, true)) { echo "y"; } else { echo "n"; }
}
echo "\n";

$e = [];
$s = "x";
var_dump(in_array($s, $e, true));

$m = ["k1" => "alpha", "k2" => "beta", "k3" => "gamma"];
var_dump(in_array("beta", $m, true));
var_dump(in_array("k2", $m, true));
var_dump(in_array("delta", $m, true));

$n = ["10", "1e1", "abc"];
var_dump(in_array("10", $n, true));
var_dump(in_array("1e1", $n, true));
var_dump(in_array("010", $n, true));
var_dump(in_array("10.0", $n, true));

$big = [];
for ($i = 0; $i < 3000; $i++) { $big[] = "k" . ($i * 3); }
$found = 0;
for ($i = 0; $i < 600; $i++) { if (in_array("k" . $i, $big, true)) { $found++; } }
echo $found, "\n";
