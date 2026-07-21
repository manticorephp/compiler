<?php

// An int array key past 2^47 must survive being cell-boxed (mixed param,
// var_dump, print_r). The cell-key box/unbox is overflow-aware, so a key that
// does not fit signed-48 rides a heap bigint instead of truncating to -1.

$a = [];
$a[9223372036854775807] = "max";   // PHP_INT_MAX
$a[281474976710655]     = "m48";   // 2^48-1 (the exact payload-mask boundary)
$a[562949953421312]     = "big";   // 2^49

echo "count=", count($a), "\n";
echo "get_max=", $a[9223372036854775807], "\n";
echo "get_m48=", $a[281474976710655], "\n";
foreach ($a as $k => $v) { echo "iter $k=$v\n"; }

// through a mixed (cell-boxed) parameter
function dump(mixed $v): void { echo "mixed_count=", count($v), "\n"; var_dump($v); }
dump($a);

// a snowflake-style id key (real-world large int)
$b = [];
$b[1420070400000000000] = "epoch";
var_dump($b);
print_r($b);
