<?php
// uasort decorate-over-usort: the int-arith comparator `$a - $b` no longer
// mis-orders / mis-boxes. The decorated pair values round-trip through the
// byref writeback de-cellified back to the param's concrete representation.
$data = ["c" => 3, "a" => 1, "b" => 2];
uasort($data, fn($x, $y) => $x - $y);
foreach ($data as $k => $v) { echo "$k=$v\n"; }

$f = ["c" => 3.5, "a" => 1.2, "b" => 2.9];
uasort($f, fn($x, $y) => $x <=> $y);
foreach ($f as $k => $v) { echo "$k=$v\n"; }

$s = ["z" => "pear", "y" => "apple", "x" => "mango"];
uasort($s, fn($a, $b) => strcmp($a, $b));
foreach ($s as $k => $v) { echo "$k=$v\n"; }
