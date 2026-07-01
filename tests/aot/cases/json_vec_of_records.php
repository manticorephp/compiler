<?php
// A vec of mixed (cell-element) assocs — the "array of records" pattern.
$data = [];
$data[] = ["id" => 1, "name" => "alpha", "on" => true];
$data[] = ["id" => 2, "name" => "beta", "on" => false];
echo json_encode($data), "\n";
// Literal form + nested array field.
echo json_encode([["id" => 10, "tags" => ["a", "b"]], ["id" => 20, "tags" => ["c"]]]), "\n";
// var_dump of the same shape (same deep-box path).
var_dump([["k" => 1, "s" => "x"], ["k" => 2, "s" => "y"]]);
