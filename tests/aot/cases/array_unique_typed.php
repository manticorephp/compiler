<?php
function show(array $a): void { echo json_encode($a), "\n"; }
show(array_unique([]));
show(array_unique([7]));
show(array_unique([3, 1, 3, 2, 1, 2, 9]));
show(array_unique([-1, 0, -1, 0, PHP_INT_MAX, PHP_INT_MAX]));
show(array_unique(["a", "b", "a", "B", "b"]));
show(array_unique(["5", "05", "5", "0", "", "0", ""]));
show(array_unique(["1", "01", "1.0", " 1", "1 ", "1e0"]));
show(array_unique([1, "1", 2, "2", 2.0, true, null, "", false, 0, "0"]));
show(array_unique(["x" => "p", "y" => "q", "z" => "p", "w" => "q"]));
show(array_unique([5 => 1, 2 => 1, 9 => 3, 4 => 3]));
show(array_unique([1.5, 1.5, "1.5", 2]));
show(array_unique(["b", "a", "c", "a"], SORT_REGULAR));
show(array_unique([4, "4", 4.0], SORT_NUMERIC));
show(array_unique(["a", "A", "a"], SORT_STRING));
// scale and order: first occurrence wins, keys preserved
$big = [];
for ($i = 0; $i < 300; $i++) { $big[] = ($i * 7) % 50; }
show(array_unique($big));
$bigS = [];
for ($i = 0; $i < 300; $i++) { $bigS[] = "k" . (($i * 7) % 50); }
show(array_unique($bigS));
$f = function (array $a) { return array_unique($a); };
show($f([2, 2, 3])); show($f(["q", "q", "r"]));
