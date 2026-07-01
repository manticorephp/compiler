<?php
// vec[int] sibling of a string-assoc: the merged element must be a cell so the
// string value is deep-boxed, not leaked as a raw pointer through a mixed read.
var_dump(["a" => [1, 2], "b" => ["x" => "y"]]);
$data = ["ints" => [10, 20], "map" => ["k1" => "v1", "k2" => "v2"]];
foreach ($data["map"] as $k => $v) { echo $k, "=", $v, " "; }
echo "\n";
