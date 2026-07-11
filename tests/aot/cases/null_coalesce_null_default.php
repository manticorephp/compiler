<?php
// `$arr[$k] ?? null` on a missing key must yield real null, not the raw 0 of
// the absent slot. A missing scalar/unknown-valued key used to render int(0)
// and defeat `=== null`, because the result was typed as the left's raw scalar.
$m = ["a" => 1, "b" => 2];
var_dump($m["x"] ?? null);              // NULL
var_dump($m["a"] ?? null);             // int(1)
echo (($m["x"] ?? null) === null ? "absent-null" : "BAD"), "\n";
echo (($m["a"] ?? null) === null ? "BAD" : "present"), "\n";

// a present falsy 0 must NOT be mistaken for absent
$z = ["k" => 0];
var_dump($z["k"] ?? null);             // int(0)
echo (($z["k"] ?? null) === null ? "BAD" : "zero-present"), "\n";

// int-keyed vector
$v = [10, 20];
var_dump($v[9] ?? null);               // NULL
var_dump($v[1] ?? null);               // int(20)

// nested missing
$n = ["a" => ["b" => 5]];
var_dump($n["a"]["z"] ?? null);        // NULL
