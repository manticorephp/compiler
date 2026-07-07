<?php
// A `null` array value must be stored as box_null so isset/`??`/read see it as
// present-null (not absent, not float(0)). Covers the all-null literal and the
// store-built homogeneous-null path (both would otherwise erase to a raw slot).

// 1: all-null assoc literal
$a = ["x" => null];
var_dump($a["x"] ?? "def");
var_dump(isset($a["x"]));
var_dump(array_key_exists("x", $a));

// 2: all-null vec literal
$v = [null, null];
var_dump($v[0] ?? "a");
var_dump(isset($v[0]));
var_dump($v);

// 3: store-built homogeneous-null vec (append)
$d = [];
$d[] = null;
$d[] = null;
var_dump($d[0] ?? "g");
var_dump(isset($d[0]));
var_dump(count($d));

// 4: store-built homogeneous-null assoc
$m = [];
$m["k"] = null;
var_dump($m["k"] ?? "e");
var_dump(isset($m["k"]));

// 5: mixed store with a null among values
$x = [];
$x["i"] = 1;
$x["n"] = null;
var_dump($x);
var_dump(isset($x["n"]), $x["n"] ?? "z");

echo json_encode(["a" => null, "b" => null]), "\n";
