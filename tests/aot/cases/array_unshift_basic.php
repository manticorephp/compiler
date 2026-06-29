<?php
$xs = [2, 3];
$n = array_unshift($xs, 1);
echo $n, ",", count($xs), ",", $xs[0], ",", $xs[1], ",", $xs[2], "\n";

$ws = ["b"];
array_unshift($ws, "a");
echo $ws[0], ",", $ws[1];
