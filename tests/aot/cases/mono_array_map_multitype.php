<?php
// Multi-type array_map (int + string + float) + array_filter in one program.
$ints  = array_map(fn($x) => $x * 2, [1, 2, 3]);
$strs  = array_map(fn($s) => $s . "!", ["a", "b", "c"]);
$flts  = array_map(fn($f) => $f + 0.5, [1.0, 2.0]);
foreach ($ints as $v) { echo $v, " "; } echo "\n";
foreach ($strs as $v) { echo $v, " "; } echo "\n";
foreach ($flts as $v) { echo $v, " "; } echo "\n";

$bigi = array_filter([1, 2, 3, 4], fn($x) => $x > 2);
$longs = array_filter(["a", "bbb", "cc"], fn($s) => strlen($s) >= 2);
foreach ($bigi as $v) { echo $v, " "; } echo "\n";
foreach ($longs as $v) { echo $v, " "; } echo "\n";
