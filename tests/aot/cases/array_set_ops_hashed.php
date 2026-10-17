<?php
$a = [1, "1", "01", 1.0, true, null, "", "a", 2, "b", 3.5, 0, "0", false];
$b = ["1", "a", 3.5, "", 0];
$c = [2, "01", true];
echo json_encode(array_diff($a, $b)), json_encode(array_diff($a, $b, $c)), "\n";
echo json_encode(array_intersect($a, $b)), json_encode(array_intersect($a, $b, [1, "a"])), json_encode(array_intersect($a)), "\n";
echo json_encode(array_diff([], $b)), json_encode(array_diff($a)), "\n";
$x = ['a' => 1, 5 => 2, "7" => 3, 'k' => null]; $y = ['a' => 9, '5' => 1, 'k' => 0]; $z = ['k' => 1];
echo json_encode(array_diff_key($x, $y)), json_encode(array_diff_key($x, $y, $z)), json_encode(array_intersect_key($x, $y)), json_encode(array_intersect_key($x, $y, $z)), "\n";
echo json_encode(array_intersect_key($x)), json_encode(array_diff_key($x)), "\n";
