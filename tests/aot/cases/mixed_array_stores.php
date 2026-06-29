<?php
// Heterogeneous assoc built via distinct-key stores — each value NaN-boxes its
// own tag (int / string / nested array), not just the literal form.
$e = [];
$e["n"] = 5;
$e["s"] = "hi";
$e["arr"] = [10, 20];
var_dump($e);
// Same key reassigned scalar -> array.
$r = [];
$r["x"] = "first";
$r["x"] = [$r["x"], "second"];
var_dump($r["x"]);
