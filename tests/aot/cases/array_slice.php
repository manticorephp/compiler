<?php
// array_slice moved to the prelude (call-site element inference types the copied
// values — a stdlib extern erased them to a denormal-float garbage); + min/max
// unboxes the ?int length cell. PHP semantics: string keys kept, int reindexed.
echo implode(",", array_slice([10, 20, 30, 40, 50], 1, 3)), "\n";  // 20,30,40
echo implode(",", array_slice([10, 20, 30, 40, 50], -2)), "\n";    // 40,50
echo implode(",", array_slice([10, 20, 30, 40], 1, -1)), "\n";     // 20,30
var_dump(array_slice(["a" => 1, "b" => 2, "c" => 3], 1, 2));       // b=>2, c=>3
var_dump(array_slice(["x", "y", "z"], 1, 5, true));                // 1=>y, 2=>z
echo count(array_slice(["p" => 1, "q" => 2, "r" => 3], 1)), "\n";  // 2
