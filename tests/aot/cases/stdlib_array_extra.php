<?php
print_r(array_combine(["a", "b", "c"], [1, 2, 3]));
print_r(array_fill_keys(["x", "y"], 0));
print_r(array_diff([1, 2, 3, 4, 5], [2, 4], [5]));
print_r(array_intersect([1, 2, 3, 4], [2, 3, 4], [3, 4, 5]));
print_r(array_diff_key(["a" => 1, "b" => 2, "c" => 3], ["b" => 9]));
print_r(array_intersect_key(["a" => 1, "b" => 2, "c" => 3], ["a" => 0, "c" => 0]));
print_r(array_unique([1, 2, 2, 3, 3, 3, 1]));
print_r(array_count_values(["a", "b", "a", "c", "b", "a"]));
print_r(array_chunk([1, 2, 3, 4, 5], 2));
print_r(array_replace(["a" => 1, "b" => 2], ["b" => 3, "c" => 4]));
$s = [1, 2];
echo "count=", array_push($s, 3, 4), "\n";
print_r($s);
