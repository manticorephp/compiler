<?php
// A bare `array` param fed a heterogeneous literal ([42,"two",3] → vec[cell],
// elements box_*'d) refines to vec[cell], so $a[$i] reads a tagged cell and the
// return stays a cell — a non-int element no longer renders as int(ptr). The P3
// $cell fallback for genuinely-heterogeneous args.
function head(array $a) { return $a[0]; }
function nth(array $a, int $i) { return $a[$i]; }
var_dump(head([42, "two", 3]));
var_dump(nth([42, "two", 3], 1));
var_dump(nth([42, "two", 3], 2));
var_dump(head(["x", 1, 2.5]));
var_dump(nth(["x", 1, 2.5], 2));
foreach ([10, "hi", 2.5, true, null] as $v) { var_dump($v); }
