<?php
$count = 0;
$inc = function() use (&$count) { $count = $count + 1; };
$inc(); $inc(); $inc();
echo $count, "\n";

$total = 0;
foreach ([1, 2, 3, 4] as $n) {
    $add = function() use (&$total, $n) { $total = $total + $n; };
    $add();
}
echo $total, "\n";

$flag = false;
$set = function() use (&$flag) { $flag = true; };
$set();
echo $flag ? "yes" : "no", "\n";
