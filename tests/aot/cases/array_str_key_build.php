<?php
// Large string-keyed build exercises the incremental bucket-index maintenance
// (index_add) across the load-factor rebuild threshold. Guards correctness of
// lookups, updates, and iteration after many appends.
$m = [];
for ($i = 0; $i < 400; $i++) { $m["item_" . $i] = $i; }
echo count($m), "\n";
echo $m["item_0"], ",", $m["item_200"], ",", $m["item_399"], "\n";
for ($i = 0; $i < 400; $i += 3) { $m["item_" . $i] = $i * 1000; }   // updates after build
echo $m["item_0"], ",", $m["item_201"], ",", $m["item_399"], "\n";
$sum = 0; $n = 0; foreach ($m as $k => $v) { $sum += $v; $n++; }
echo $n, ",", $sum, "\n";
$m["item_405"] = -1;                                                 // append after rebuild
echo count($m), ",", $m["item_405"], "\n";
