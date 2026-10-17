<?php
// Spreading an array whose int keys do not start at 0 into a variadic builtin throws ArgumentCountError.
// issue: #313
$l = [1 => [1 => 1, 2 => 2], 2 => [2 => 5, 3 => 6]];
echo json_encode(array_intersect_key(...$l)), "\n";
